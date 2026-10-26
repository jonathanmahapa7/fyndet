<?php
declare(strict_types=1);
/** PayFast hosted recurring checkout. Live mode additionally requires an explicit approval gate. */
function pfMode():string{return cfg('PAYFAST_MODE','sandbox');}
function pfEnabled():bool{return cfg('PAYFAST_ENABLED','0')==='1'&&in_array(pfMode(),['sandbox','live'],true)&&(pfMode()==='sandbox'||cfg('PAYFAST_LIVE_APPROVED','0')==='1')&&cfg('PAYFAST_MERCHANT_ID')!==''&&cfg('PAYFAST_MERCHANT_KEY')!==''&&cfg('PAYFAST_PASSPHRASE')!=='';}
function pfZarCentsForUsd(int $usdCents,float $rate):int{
 if($usdCents<=0||!is_finite($rate)||$rate<5||$rate>100)return 0;
 return (int)round($usdCents*$rate);
}
function pfAmount(string $plan):int{
 if(!in_array($plan,['starter','pro','business'],true))return 0;
 if(cfg('PLATFORM_CURRENCY','ZAR')==='USD'){
  // PayFast checkout is ZAR. The ZAR quote is captured per order.
  if(!function_exists('fyndetFxRate')||!function_exists('fyndetUsdCents'))return 0;
  $fx=fyndetFxRate();
  if(!$fx || time()-(int)$fx['fetched_at']>86400)return 0;
  return pfZarCentsForUsd(fyndetUsdCents($plan),(float)$fx['rate']);
 }
 $raw=cfg('PAYFAST_PRICE_'.strtoupper($plan).'_ZAR');
 if(!preg_match('/^[1-9][0-9]{0,5}(?:\\.[0-9]{2})?$/',$raw))return 0;
 return (int)round((float)$raw*100);
}
function pfParameterString(array $fields):string{
 unset($fields['signature']);$parts=[];
 foreach($fields as $k=>$v)if($v!==''&&$v!==null)$parts[]=(string)$k.'='.urlencode(trim((string)$v));
 return implode('&',$parts);
}
/** Keep hosted subscription fields in PayFast's documented signature order. */
function pfCheckoutSubscriptionFields(int $cents):array{
 $amount=number_format($cents/100,2,'.','');
 return ['subscription_type'=>'1','recurring_amount'=>$amount,'frequency'=>'3','cycles'=>'0'];
}
function pfSignature(array $fields,string $passphrase):string{
 return md5(pfParameterString($fields).'&passphrase='.urlencode(trim($passphrase)));
}
function pfGrossCents(mixed $value):?int{
 if(!is_string($value)&&!is_int($value))return null;
 $amount=(string)$value;
 if(!preg_match('/^(?:0|[1-9][0-9]{0,7})\\.[0-9]{2}$/D',$amount))return null;
 [$rand,$cent]=explode('.',$amount,2);
 return ((int)$rand)*100+(int)$cent;
}
function pfItnParameterString(array $fields):string{
 $parts=[];
 foreach($fields as $key=>$value){
  if($key==='signature')break;
  $parts[]=(string)$key.'='.urlencode((string)$value);
 }
 return implode('&',$parts);
}
function pfCheckoutCsp(string $mode):string{
 $gateway=$mode==='live'?'https://www.payfast.co.za':'https://sandbox.payfast.co.za';
 return "default-src 'none'; base-uri 'none'; form-action ".$gateway."; frame-ancestors 'none'";
}
function pfReusablePendingOrder(array $existing,string $plan,int $cents):string{
 if(!hash_equals((string)($existing['plan']??''),$plan)||(int)($existing['amount_cents']??-1)!==$cents)
  throw new DomainException('Another checkout is already pending. Please complete or cancel that checkout before changing plans.');
 $id=(string)($existing['id']??'');
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid pending checkout reference.');
 return $id;
}
function pfPendingAction(?array $existing,string $plan,int $cents,string $mode):string{
 if(!$existing)return 'create';
 if(hash_equals((string)($existing['plan']??''),$plan)&&(int)($existing['amount_cents']??-1)===$cents)return 'reuse';
 // Changing or replacing an unconfirmed quote is safe in sandbox, never in live.
 if($mode==='sandbox')return 'supersede';
 throw new DomainException('An earlier checkout is pending. Please contact support before changing a live payment quote.');
}
function pfCheckout():never{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 verifyCsrf();$user=mustUser();
 if(empty($user['email_verified_at'])||!pfEnabled())throw new DomainException('PayFast checkout is not configured or email is not verified.');
 $plan=(string)($_POST['plan']??'');$cents=pfAmount($plan);
 if($cents<500)throw new DomainException('This subscription is temporarily unavailable while its payment quote is refreshed.');
 $q=db()->prepare('SELECT stripe_customer_id,subscription_status FROM users WHERE id=?');$q->execute([$user['id']]);$account=$q->fetch();
 if(!empty($account['stripe_customer_id'])||in_array((string)$account['subscription_status'],['active','trialing','past_due','unpaid'],true))throw new DomainException('Existing provider-managed subscription must be resolved before a new PayFast checkout.');
 $q=db()->prepare("SELECT COUNT(*) FROM payfast_subscriptions WHERE user_id=? AND status IN ('active','pending','paused')");
 $q->execute([$user['id']]);if((int)$q->fetchColumn()>0)throw new DomainException('This account already has a PayFast subscription; contact support for changes.');
 // Serialise checkout creation by account and reuse a matching recent pending order.
 // Reusing the merchant reference avoids duplicate pending records and lockout.
 $pdo=db();$pdo->beginTransaction();
 try{
  $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
  $lock->execute([$user['id']]);
  if(!$lock->fetchColumn())throw new DomainException('Account no longer exists.');
  $pending=$pdo->prepare("SELECT id,plan,amount_cents FROM payfast_orders WHERE user_id=? AND mode=? AND status='pending' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) ORDER BY created_at DESC LIMIT 1");
  $pending->execute([$user['id'],pfMode()]);$existing=$pending->fetch();
  $action=pfPendingAction($existing?:null,$plan,$cents,pfMode());
  if($action==='reuse'){
   $order=pfReusablePendingOrder($existing,$plan,$cents);
  }else{
   if($action==='supersede'){
    // Only the account-owned, unconfirmed sandbox order is marked obsolete.
    // A delayed COMPLETE notification for the obsolete order must not grant access.
    $expire=$pdo->prepare("UPDATE payfast_orders SET status='superseded' WHERE id=? AND user_id=? AND mode='sandbox' AND status='pending'");
    $expire->execute([$existing['id'],$user['id']]);
    if($expire->rowCount()!==1)throw new RuntimeException('Pending checkout changed; retry.');
   }
   $order=bin2hex(random_bytes(16));
   $insert=$pdo->prepare("INSERT INTO payfast_orders(id,user_id,plan,amount_cents,mode,status) VALUES (?,?,?,?,?,'pending')");
   $insert->execute([$order,$user['id'],$plan,$cents,pfMode()]);
  }
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $base=rtrim(cfg('APP_URL'),'/');
 if(!str_starts_with($base,'https://'))throw new DomainException('Secure application URL required.');
 $fields=['merchant_id'=>cfg('PAYFAST_MERCHANT_ID'),'merchant_key'=>cfg('PAYFAST_MERCHANT_KEY'),'return_url'=>$base.'/app/billing?success=1','cancel_url'=>$base.'/app/billing?cancel=1','notify_url'=>$base.'/payfast/notify','name_first'=>mb_substr((string)$user['name'],0,100),'email_address'=>$user['email'],'m_payment_id'=>$order,'amount'=>number_format($cents/100,2,'.',''),'item_name'=>'FyndEt '.ucfirst($plan).' monthly subscription',...pfCheckoutSubscriptionFields($cents)];
 $fields['signature']=pfSignature($fields,cfg('PAYFAST_PASSPHRASE'));
 header('Content-Type: text/html; charset=UTF-8');
 header('Cache-Control: no-store');
 $gateway=pfMode()==='live'?'https://www.payfast.co.za/eng/process':'https://sandbox.payfast.co.za/eng/process';
 // This standalone checkout page posts directly to PayFast. The global CSP
 // limits form submission to self and would otherwise block the hosted gateway.
 // Restrict the exception to the exact active environment, never both.
 header('Content-Security-Policy: '.pfCheckoutCsp(pfMode()));
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>FyndEt subscription checkout</title></head><body><h1>FyndEt '.esc(ucfirst(pfMode())).' checkout</h1><p>Plan: '.esc(ucfirst($plan)).' — USD '.esc(number_format(fyndetUsdCents($plan)/100,2,'.',',')).' per month. '.(pfMode()==='sandbox'?'Sandbox simulation; no real payment.':'The payment provider will confirm its settlement currency and the recurring amount before you pay.').'</p><form action="'.esc($gateway).'" method="post">';
 foreach($fields as $k=>$v)echo '<input type="hidden" name="'.esc($k).'" value="'.esc((string)$v).'">';
 echo '<button type="submit">Continue to PayFast</button></form></body></html>';exit;
}
function pfServerValidation(string $body):bool{
 if(!function_exists('curl_init'))return false;
 $ch=curl_init(pfMode()==='live'?'https://www.payfast.co.za/eng/query/validate':'https://sandbox.payfast.co.za/eng/query/validate');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
 $answer=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 return $status===200&&is_string($answer)&&trim($answer)==='VALID';
}
/** Renewal and subscription lifecycle guards. No automatic grants on failed renewals. */
function pfRenewalPolicy(array $order,?array $subscription,string $status,string $paymentId,string $token):string{
 if(($order['status']??'')==='superseded')return 'reject';
 if($status!=='COMPLETE')return 'record_only';
 if($subscription && ($subscription['status']??'')==='cancelled' && ($subscription['order_id']??'')===($order['id']??''))return 'record_only';
 if($subscription && ($subscription['mode']??'')!==($order['mode']??''))return 'reject';
 if($subscription && ($subscription['order_id']??'')!==($order['id']??'') && ($subscription['status']??'')!=='cancelled')return 'reject';
 if($subscription && !empty($subscription['token']) && $token!=='' && !hash_equals((string)$subscription['token'],$token))return 'reject';
 return 'activate';
}
/** Preserve original checkout while accepting only explicit provider-confirmed change quotes. */
function pfEffectivePlanForRenewal(string $original,string $amountDecision,?array $change):string{
 if($amountDecision==='changed'&&$change&&in_array((string)($change['status']??''),['provider_confirmed','applied'],true)&&in_array((string)($change['to_plan']??''),['starter','pro','business'],true))return (string)$change['to_plan'];
 return $original;
}
function pfItnAmountDecision(int $initialCents,int $grossCents,?array $change):string{
 if($grossCents===$initialCents)return ($change&&($change['status']??'')==='applied')?'reject':'original';
 if($change&&in_array((string)($change['status']??''),['provider_confirmed','applied'],true)&&$grossCents===(int)($change['quoted_zar_cents']??-1))return 'changed';
 return 'reject';
}
function pfNotify():never{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 if(!pfEnabled()){http_response_code(503);exit('PayFast disabled');}
 $raw=file_get_contents('php://input',false,null,0,32768);
 if(!is_string($raw)||strlen($raw)>=32768){http_response_code(400);exit('Invalid payload');}
 $fields=[];parse_str($raw,$fields);
 if(!is_array($fields)||count($fields)<5||!isset($fields['signature'])){http_response_code(400);exit('Missing fields');}
 foreach($fields as $value)if(!is_scalar($value)){http_response_code(400);exit('Invalid field');}
 $claimed=strtolower((string)$fields['signature']);$notificationString=pfItnParameterString($fields);$expected=md5($notificationString.'&passphrase='.urlencode(trim(cfg('PAYFAST_PASSPHRASE'))));
 if(!hash_equals($expected,$claimed)){http_response_code(403);exit('Invalid signature');}
 if(!hash_equals(cfg('PAYFAST_MERCHANT_ID'),(string)($fields['merchant_id']??''))){http_response_code(403);exit('Merchant mismatch');}
 if(!pfServerValidation($notificationString)){http_response_code(403);exit('Source confirmation failed');}
 $order=(string)($fields['m_payment_id']??'');$payment=(string)($fields['pf_payment_id']??'');
 $state=(string)($fields['payment_status']??'');
 if(!preg_match('/^[a-f0-9]{32}$/',$order)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$payment)||!in_array($state,['COMPLETE','FAILED','PENDING','CANCELLED'],true)){http_response_code(400);exit('Invalid transaction');}
 $db=db();$db->beginTransaction();
 try {
  $q=$db->prepare('SELECT id,user_id,plan,amount_cents,mode,status,payment_id FROM payfast_orders WHERE id=? FOR UPDATE');$q->execute([$order]);$item=$q->fetch();
  if(!$item||$item['status']==='superseded'||$item['mode']!==pfMode()){$db->rollBack();http_response_code(400);exit('Order mismatch');}
  $gross=pfGrossCents($fields['amount_gross']??null);
  $changed=$db->prepare("SELECT id,from_plan,to_plan,quoted_zar_cents,status FROM payfast_plan_changes WHERE user_id=? AND order_id=? AND status IN ('provider_confirmed','applied') ORDER BY id DESC LIMIT 1 FOR UPDATE");
  $changed->execute([$item['user_id'],$order]);$change=$changed->fetch()?:null;
  $amountDecision=$gross===null?'reject':pfItnAmountDecision((int)$item['amount_cents'],$gross,$change);
  if($amountDecision==='reject'){$db->rollBack();http_response_code(400);exit('Amount mismatch');}
  // Lock the user's subscription before recording the event. Never allow an older
  // merchant order or an old sandbox subscription to replace a newer subscription.
  $existingSub=$db->prepare('SELECT order_id,status,token,mode FROM payfast_subscriptions WHERE user_id=? FOR UPDATE');
  $existingSub->execute([$item['user_id']]);$previous=$existingSub->fetch();
  if($previous && $previous['status']!=='cancelled' && ($previous['mode']!==$item['mode'] || !hash_equals((string)$previous['order_id'],$order))){
   $db->rollBack();http_response_code(409);exit('Subscription order mismatch');
  }
  // A payment ID must never be silently accepted against another order.
  $dup=$db->prepare('SELECT order_id FROM payfast_notifications WHERE payment_id=? AND payment_status=?');
  $dup->execute([$payment,$state]);$dupOrder=$dup->fetchColumn();
  if($dupOrder!==false){$db->rollBack();if(!hash_equals((string)$dupOrder,$order)){http_response_code(409);exit('Payment reference mismatch');}http_response_code(200);exit('Already processed');}
  $token=trim((string)($fields['token']??''));
  $policy=pfRenewalPolicy($item,$previous?:null,$state,$payment,$token);
  if($policy==='reject'){$db->rollBack();http_response_code(409);exit('Subscription state mismatch');}
  // Validated renewals create new payment records; the first payment remains the order reference.
  $q=$db->prepare('INSERT INTO payfast_notifications(payment_id,payment_status,order_id) VALUES(?,?,?)');$q->execute([$payment,$state,$order]);
  if($q->rowCount()===0){$db->commit();http_response_code(200);exit('Already processed');}
  if($state==='COMPLETE'){
   $q=$db->prepare("UPDATE payfast_orders SET status='complete',payment_id=COALESCE(payment_id,?) WHERE id=?");$q->execute([$payment,$order]);
    $cancelledOriginal=$previous&&$previous['status']==='cancelled'&&hash_equals((string)$previous['order_id'],$order);
   $q=$db->prepare("INSERT INTO payfast_subscriptions(user_id,order_id,token,plan,status,mode) VALUES(?,?,?,?,'active',?) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id),token=COALESCE(VALUES(token),token),plan=VALUES(plan),status='active',mode=VALUES(mode)");
   if(pfMode()==='live'&&$token===''&&empty($previous['token']))throw new RuntimeException('Missing recurring subscription token; retry notification');
   $effectivePlan=pfEffectivePlanForRenewal((string)$item['plan'],$amountDecision,$change);
   if($policy==='activate')$q->execute([$item['user_id'],$order,$token!==''?$token:null,$effectivePlan,pfMode()]);
   // Only validated LIVE notifications grant paid investigation entitlements.
   if(pfMode()==='live'&&$policy==='activate'){$q=$db->prepare("UPDATE users SET plan=?,subscription_status='active' WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id='')");$q->execute([$effectivePlan,$item['user_id']]);}
   $q=$db->prepare("INSERT IGNORE INTO payfast_invoices(invoice_number,user_id,payment_id,order_id,amount_cents,currency,status) VALUES(?,?,?,?,?,'ZAR',?)");
   $q->execute(['PF-'.strtoupper(substr(hash('sha256',$payment),0,32)),$item['user_id'],$payment,$order,$gross,pfMode()==='live'?'paid':'sandbox_paid']);
   if($policy==='activate' && $amountDecision==='changed' && $change && $change['status']==='provider_confirmed'){
    $q=$db->prepare("UPDATE payfast_plan_changes SET status='applied' WHERE id=? AND status='provider_confirmed'");
    $q->execute([$change['id']]);
    $q=$db->prepare("UPDATE payfast_subscriptions SET plan=? WHERE user_id=? AND order_id=? AND status='active'");
    $q->execute([$change['to_plan'],$item['user_id'],$order]);
    if(pfMode()==='live'){
     $q=$db->prepare("UPDATE users SET plan=?,subscription_status='active' WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id='')");
     $q->execute([$change['to_plan'],$item['user_id']]);
    }
   }
  }elseif($state==='FAILED'||$state==='CANCELLED'){
   // Failure of a renewal is an exception requiring reconciliation. Do not
   // automatically revoke an already-paid term without verified period data.
   $q=$db->prepare('UPDATE payfast_orders SET status=?,payment_id=? WHERE id=? AND status!=\'complete\'');$q->execute([strtolower($state),$payment,$order]);
  }
  $db->commit();http_response_code(200);exit('OK');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('FyndEt PayFast ITN: '.$e->getMessage());http_response_code(500);exit('Retry');}
}
if($path==='/payfast/notify')pfNotify();
if($path==='/payfast/checkout')pfCheckout();
