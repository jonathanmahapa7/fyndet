<?php
declare(strict_types=1);
/**
 * Stripe adapter for FyndEt.
 * Loaded by public/index.php after bootstrap.
 * Never trust client-submitted prices; always map an allowlisted plan to a server-side Stripe Price ID.
 * Billing remains disabled unless Stripe keys and verified price IDs are configured.
 */
function stripePrice(string $plan):string {
 return match($plan){
  'starter'=>cfg('STRIPE_PRICE_STARTER'),
  'pro'=>cfg('STRIPE_PRICE_PRO'),
  'business'=>cfg('STRIPE_PRICE_BUSINESS'),
  default=>''
 };
}
function stripeCall(string $endpoint,array $body=[]):array {
 $key=cfg('STRIPE_SECRET_KEY');
 if(!str_starts_with($key,'sk_'))throw new DomainException('Paid billing has not yet been configured.');
 if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required for billing.');
 $h=curl_init('https://api.stripe.com/v1/'.$endpoint);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/x-www-form-urlencoded'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $response=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
 curl_close($h);$payload=json_decode((string)$response,true);
 if($code<200||$code>=300||!is_array($payload))throw new RuntimeException('Payment provider declined the request. Please contact support.');
 return $payload;
}
function checkout():never {
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed.');}
 verifyCsrf();$u=mustUser();
 // Subscription checkout is restricted until email ownership is verified.
 if(empty($u['email_verified_at']))throw new DomainException('Verify your email before subscribing.');
 $plan=(string)($_POST['plan']??'');$price=stripePrice($plan);
 if(!$price||!str_starts_with($price,'price_'))throw new DomainException('This subscription is not yet available for checkout.');
 $app=rtrim(cfg('APP_URL'),' /');
 if(!str_starts_with($app,'https://'))throw new DomainException('HTTPS production application URL required for checkout.');
 $body=['mode'=>'subscription','line_items'=>[['price'=>$price,'quantity'=>1]],'success_url'=>$app.'/app/billing?success=1','cancel_url'=>$app.'/app/billing?cancel=1','client_reference_id'=>(string)$u['id'],'metadata'=>['user_id'=>(string)$u['id'],'plan'=>$plan],'subscription_data'=>['metadata'=>['user_id'=>(string)$u['id']]],'allow_promotion_codes'=>'true'];
 // A registered Stripe customer may only be reused from our private database, not user input.
 $q=db()->prepare('SELECT stripe_customer_id FROM users WHERE id=?');$q->execute([$u['id']]);$customer=$q->fetchColumn();
 if($customer&&str_starts_with((string)$customer,'cus_'))$body['customer']=$customer;
 else $body['customer_email']=$u['email'];
 $result=stripeCall('checkout/sessions',$body);
 $checkoutUrl=$result['url']??'';
 if(!is_string($checkoutUrl)||!str_starts_with($checkoutUrl,'https://checkout.stripe.com/'))throw new RuntimeException('Invalid hosted checkout URL.');
 header('Location: '.$checkoutUrl,true,303);exit;
}
function webhook():never {
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 $secret=cfg('STRIPE_WEBHOOK_SECRET');
 if(!str_starts_with($secret,'whsec_')){http_response_code(503);exit('Webhook not configured');}
 $raw=file_get_contents('php://input',false,null,0,1000000);
 $signature=$_SERVER['HTTP_STRIPE_SIGNATURE']??'';$parsed=[];
 foreach(explode(',',$signature) as $part)if(str_contains($part,'=')){[$key,$val]=explode('=',$part,2);$parsed[trim($key)][]=trim($val);}
 $ts=(int)($parsed['t'][0]??0);$digests=$parsed['v1']??[];
 if(!$ts||abs(time()-$ts)>300||!$digests){http_response_code(400);exit('Invalid signature');}
 $expected=hash_hmac('sha256',$ts.'.'.$raw,$secret);$valid=false;
 foreach($digests as $candidate)if(hash_equals($expected,$candidate)){$valid=true;break;}
 if(!$valid){http_response_code(400);exit('Invalid signature');}
 $event=json_decode($raw,true);
 if(!is_array($event)||empty($event['id'])||empty($event['type'])){http_response_code(400);exit('Invalid event');}
 $type=(string)$event['type'];$obj=$event['data']['object']??[];
 try{
  $db=db();$db->beginTransaction();
  $ins=$db->prepare('INSERT IGNORE INTO billing_events(event_id) VALUES (?)');$ins->execute([$event['id']]);
  if($ins->rowCount()===0){$db->commit();http_response_code(200);exit('Already processed');}
  if($type==='checkout.session.completed'){
   $uid=(int)($obj['client_reference_id']??0);$customer=(string)($obj['customer']??'');
   if($uid>0&&str_starts_with($customer,'cus_')){
    $q=$db->prepare('UPDATE users SET stripe_customer_id=? WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id=?)');
    $q->execute([$customer,$uid,$customer]);
   }
  }
  if(in_array($type,['customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'],true)){
   $customer=(string)($obj['customer']??'');
   $uid=(int)($obj['metadata']['user_id']??0);
   $stripePrice=(string)($obj['items']['data'][0]['price']['id']??'');
   $plan='free';
   foreach(['starter','pro','business'] as $candidate)if(stripePrice($candidate)!=='' && hash_equals(stripePrice($candidate),$stripePrice)){$plan=$candidate;break;}
   $status=(string)($obj['status']??'unknown');
   if(!in_array($status,['active','trialing'],true)||$type==='customer.subscription.deleted')$plan='free';
   if($uid>0&&str_starts_with($customer,'cus_')){
    // Bind uid to verified billing customer, prevent unrelated customer metadata from overwriting an account.
    $q=$db->prepare('UPDATE users SET plan=?,subscription_status=?,stripe_customer_id=? WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id=?)');
    $q->execute([$plan,$status,$customer,$uid,$customer]);
   }elseif(str_starts_with($customer,'cus_')){
    $q=$db->prepare('UPDATE users SET plan=?,subscription_status=? WHERE stripe_customer_id=?');
    $q->execute([$plan,$status,$customer]);
   }
  }
  $db->commit();http_response_code(200);exit('OK');
 }catch(Throwable $e){
  if(isset($db)&&$db->inTransaction())$db->rollBack();
  error_log('FyndEt Stripe webhook failed: '.$e->getMessage());http_response_code(500);exit('Temporary failure');
 }
}
if($path==='/billing/webhook')webhook();
if($path==='/billing/checkout')checkout();
