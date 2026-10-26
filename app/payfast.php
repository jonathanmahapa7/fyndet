<?php
declare(strict_types=1);
/** PayFast hosted recurring checkout. Sandbox-only until full acceptance testing. */
function pfEnabled():bool{return cfg('PAYFAST_ENABLED','0')==='1'&&cfg('PAYFAST_MODE','sandbox')==='sandbox'&&cfg('PAYFAST_MERCHANT_ID')!==''&&cfg('PAYFAST_MERCHANT_KEY')!==''&&cfg('PAYFAST_PASSPHRASE')!=='';}
function pfAmount(string $plan):int{
 if(!in_array($plan,['starter','pro','business'],true))return 0;
 $raw=cfg('PAYFAST_PRICE_'.strtoupper($plan).'_ZAR');
 if(!preg_match('/^[1-9][0-9]{0,5}(?:\.[0-9]{2})?$/',$raw))return 0;
 return (int)round((float)$raw*100);
}
function pfSignature(array $fields,string $passphrase):string{
 unset($fields['signature']);$parts=[];
 foreach($fields as $k=>$v)if($v!==''&&$v!==null)$parts[]=urlencode((string)$k).'='.urlencode(trim((string)$v));
 return md5(implode('&',$parts).'&passphrase='.urlencode($passphrase));
}
function pfCheckout():never{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 verifyCsrf();$user=mustUser();
 if(empty($user['email_verified_at'])||!pfEnabled())throw new DomainException('PayFast sandbox checkout is not configured or email is not verified.');
 $plan=(string)($_POST['plan']??'');$cents=pfAmount($plan);
 if($cents<500)throw new DomainException('This PayFast plan has no valid ZAR price.');
 $q=db()->prepare('SELECT stripe_customer_id,subscription_status FROM users WHERE id=?');$q->execute([$user['id']]);$account=$q->fetch();
 if(!empty($account['stripe_customer_id'])||in_array((string)$account['subscription_status'],['active','trialing','past_due','unpaid'],true))throw new DomainException('Existing provider-managed subscription must be resolved before a new PayFast checkout.');
 $q=db()->prepare("SELECT COUNT(*) FROM payfast_subscriptions WHERE user_id=? AND status IN ('active','pending','paused')");
 $q->execute([$user['id']]);if((int)$q->fetchColumn()>0)throw new DomainException('This account already has a PayFast subscription; contact support for changes.');
 $order=bin2hex(random_bytes(16));
 $q=db()->prepare("INSERT INTO payfast_orders(id,user_id,plan,amount_cents,mode,status) VALUES (?,?,?,?,'sandbox','pending')");
 $q->execute([$order,$user['id'],$plan,$cents]);
 $base=rtrim(cfg('APP_URL'),'/');
 if(!str_starts_with($base,'https://'))throw new DomainException('Secure application URL required.');
 $fields=['merchant_id'=>cfg('PAYFAST_MERCHANT_ID'),'merchant_key'=>cfg('PAYFAST_MERCHANT_KEY'),'return_url'=>$base.'/app/billing?success=1','cancel_url'=>$base.'/app/billing?cancel=1','notify_url'=>$base.'/payfast/notify','name_first'=>mb_substr((string)$user['name'],0,100),'email_address'=>$user['email'],'m_payment_id'=>$order,'amount'=>number_format($cents/100,2,'.',''),'item_name'=>'FyndEt '.ucfirst($plan).' monthly subscription','subscription_type'=>'1','frequency'=>'3','cycles'=>'0','recurring_amount'=>number_format($cents/100,2,'.','')];
 $fields['signature']=pfSignature($fields,cfg('PAYFAST_PASSPHRASE'));
 header('Content-Type: text/html; charset=UTF-8');
 header('Cache-Control: no-store');
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Proceed to PayFast Sandbox</title></head><body><h1>Secure sandbox checkout</h1><p>Test subscription: R'.esc(number_format($cents/100,2)).' per month. No real payment will be taken in sandbox.</p><form action="https://sandbox.payfast.co.za/eng/process" method="post">';
 foreach($fields as $k=>$v)echo '<input type="hidden" name="'.esc($k).'" value="'.esc((string)$v).'">';
 echo '<button type="submit">Continue to PayFast Sandbox</button></form></body></html>';exit;
}
function pfServerValidation(string $body):bool{
 if(!function_exists('curl_init'))return false;
 $ch=curl_init('https://sandbox.payfast.co.za/eng/query/validate');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
 $answer=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 return $status===200&&is_string($answer)&&trim($answer)==='VALID';
}
function pfNotify():never{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 if(!pfEnabled()){http_response_code(503);exit('PayFast disabled');}
 $raw=file_get_contents('php://input',false,null,0,32768);
 if(!is_string($raw)||strlen($raw)>=32768){http_response_code(400);exit('Invalid payload');}
 $fields=[];parse_str($raw,$fields);
 if(!is_array($fields)||count($fields)<5||!isset($fields['signature'])){http_response_code(400);exit('Missing fields');}
 foreach($fields as $value)if(!is_scalar($value)){http_response_code(400);exit('Invalid field');}
 $claimed=strtolower((string)$fields['signature']);$expected=pfSignature($fields,cfg('PAYFAST_PASSPHRASE'));
 if(!hash_equals($expected,$claimed)){http_response_code(403);exit('Invalid signature');}
 if(!hash_equals(cfg('PAYFAST_MERCHANT_ID'),(string)($fields['merchant_id']??''))){http_response_code(403);exit('Merchant mismatch');}
 if(!pfServerValidation($raw)){http_response_code(403);exit('Source confirmation failed');}
 $order=(string)($fields['m_payment_id']??'');$payment=(string)($fields['pf_payment_id']??'');
 $state=(string)($fields['payment_status']??'');
 if(!preg_match('/^[a-f0-9]{32}$/',$order)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$payment)||!in_array($state,['COMPLETE','FAILED','PENDING','CANCELLED'],true)){http_response_code(400);exit('Invalid transaction');}
 $db=db();$db->beginTransaction();
 try {
  $q=$db->prepare('SELECT id,user_id,plan,amount_cents,mode,status,payment_id FROM payfast_orders WHERE id=? FOR UPDATE');$q->execute([$order]);$item=$q->fetch();
  if(!$item||$item['mode']!=='sandbox'||!hash_equals(number_format((int)$item['amount_cents']/100,2,'.',''),number_format((float)($fields['amount_gross']??-1),2,'.',''))){$db->rollBack();http_response_code(400);exit('Order mismatch');}
  if(!empty($item['payment_id'])&&!hash_equals((string)$item['payment_id'],$payment)){$db->rollBack();http_response_code(409);exit('Payment mismatch');}
  $q=$db->prepare('INSERT IGNORE INTO payfast_notifications(payment_id,payment_status,order_id) VALUES(?,?,?)');$q->execute([$payment,$state,$order]);
  if($q->rowCount()===0){$db->commit();http_response_code(200);exit('Already processed');}
  if($state==='COMPLETE'){
   $q=$db->prepare("UPDATE payfast_orders SET status='complete',payment_id=? WHERE id=?");$q->execute([$payment,$order]);
   $q=$db->prepare("INSERT INTO payfast_subscriptions(user_id,order_id,token,plan,status,mode) VALUES(?,?,?,?,'active','sandbox') ON DUPLICATE KEY UPDATE token=COALESCE(VALUES(token),token),plan=VALUES(plan),status='active'");
   $token=trim((string)($fields['token']??''));$q->execute([$item['user_id'],$order,$token!==''?$token:null,$item['plan']]);
   $q=$db->prepare("UPDATE users SET plan=?,subscription_status='active' WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id='')");$q->execute([$item['plan'],$item['user_id']]);
   $q=$db->prepare("INSERT IGNORE INTO payfast_invoices(invoice_number,user_id,payment_id,order_id,amount_cents,currency,status) VALUES(?,?,?,?,?,'ZAR','paid')");
   $q->execute(['PF-'.strtoupper(substr($payment,0,40)),$item['user_id'],$payment,$order,$item['amount_cents']]);
  }elseif($state==='FAILED'||$state==='CANCELLED'){
   $q=$db->prepare('UPDATE payfast_orders SET status=?,payment_id=? WHERE id=? AND status!=\'complete\'');$q->execute([strtolower($state),$payment,$order]);
  }
  $db->commit();http_response_code(200);exit('OK');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('FyndEt PayFast ITN: '.$e->getMessage());http_response_code(500);exit('Retry');}
}
if($path==='/payfast/notify')pfNotify();
if($path==='/payfast/checkout')pfCheckout();
