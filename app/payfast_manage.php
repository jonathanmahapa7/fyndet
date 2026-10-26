<?php
declare(strict_types=1);
/** Signed PayFast recurring cancellation and customer invoice records. */
function pfApiSignature(array $values):string{
 $values['passphrase']=cfg('PAYFAST_PASSPHRASE');
 ksort($values,SORT_STRING);
 return md5(http_build_query(array_filter($values,static fn($v)=>$v!==''&&$v!==null),'','&',PHP_QUERY_RFC1738));
}
function pfApiCancel(string $token):bool{
 if(!pfEnabled()||!preg_match('/^[a-f0-9-]{36}$/i',$token))return false;
 $timestamp=gmdate('Y-m-d\TH:i:s+00:00');
 $headers=['merchant-id'=>cfg('PAYFAST_MERCHANT_ID'),'version'=>'v1','timestamp'=>$timestamp];
 $sig=pfApiSignature($headers);
 if(!function_exists('curl_init'))throw new RuntimeException('cURL unavailable.');
 $url='https://api.payfast.co.za/subscriptions/'.$token.'/cancel'.(pfMode()==='sandbox'?'?testing=true':'');
 $h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['merchant-id: '.$headers['merchant-id'],'version: v1','timestamp: '.$timestamp,'signature: '.$sig]]);
 $response=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 $data=is_string($response)?json_decode($response,true):null;
 return $status===200&&is_array($data)&&($data['status']??'')==='success'&&($data['data']['response']??null)===true;
}
if($path==='/payfast/cancel'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$user=mustUser();if(!pfEnabled())throw new DomainException('PayFast is unavailable.');
 $q=db()->prepare("SELECT token,status,mode FROM payfast_subscriptions WHERE user_id=?");$q->execute([$user['id']]);$subscription=$q->fetch();
 if(!$subscription||$subscription['mode']!==pfMode()||$subscription['status']!=='active'||empty($subscription['token']))throw new DomainException('No active PayFast subscription token is available.');
 if(!pfApiCancel((string)$subscription['token']))throw new DomainException('PayFast did not confirm cancellation. No local change was made.');
 $db=db();$db->beginTransaction();
 try{$q=$db->prepare("UPDATE payfast_subscriptions SET status='cancelled' WHERE user_id=? AND mode=?");$q->execute([$user['id'],pfMode()]);
 if(pfMode()==='live'){$q=$db->prepare("UPDATE users SET plan='free',subscription_status='cancelled' WHERE id=? AND (stripe_customer_id IS NULL OR stripe_customer_id='')");$q->execute([$user['id']]);}
 auditEvent((int)$user['id'],'billing.payfast.cancelled',pfMode());$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 flash('PayFast confirmed cancellation. Your subscription is no longer active.');go('app/billing');
}
if($path==='/payfast/invoice'){
 $user=mustUser();$invoice=(int)($_GET['id']??0);
 if($invoice<1)throw new DomainException('Invalid receipt ID.');
 $q=db()->prepare('SELECT i.*,o.plan,o.mode,u.email,u.name FROM payfast_invoices i JOIN payfast_orders o ON o.id=i.order_id JOIN users u ON u.id=i.user_id WHERE i.id=? AND i.user_id=?');
 $q->execute([$invoice,$user['id']]);$r=$q->fetch();
 if(!$r){http_response_code(404);exit('Receipt not found');}
 pageTop('PayFast payment record');
 echo '<main class="section container"><section class="panel" style="max-width:780px"><p class="eyebrow">FYNDET / PAYMENT RECORD</p><h1>'.esc((string)$r['invoice_number']).'</h1><p class="muted">'.($r['mode']==='sandbox'?'This is a sandbox payment record, not evidence of funds received.':'Payment receipt only — this document is not a VAT tax invoice.').'</p><p>Customer: '.esc((string)$r['name']).' ('.esc((string)$r['email']).')</p><p>Plan: '.esc(ucfirst((string)$r['plan'])).'</p><p>Amount: ZAR '.esc(number_format((int)$r['amount_cents']/100,2)).'</p><p>Payment ID: '.esc((string)$r['payment_id']).'</p><p>Recorded: '.esc((string)$r['issued_at']).'</p><p>Status: '.esc((string)$r['status']).'</p><a href="/app/billing" class="under-link">Return to billing</a></section></main>';pageEnd();exit;
}
