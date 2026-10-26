<?php
declare(strict_types=1);
/** Signed PayFast recurring cancellation and customer invoice records. */
function pfApiSignature(array $values):string{
 $values['passphrase']=cfg('PAYFAST_PASSPHRASE');
 ksort($values,SORT_STRING);
 return md5(http_build_query(array_filter($values,static fn($v)=>$v!==''&&$v!==null),'','&',PHP_QUERY_RFC1738));
}
/** A provider-side CANCELLED state is the only cancellation confirmation. */
function pfApiSubscriptionCancelledResponse(int $http,mixed $payload):bool{
 return $http===200 && is_array($payload) &&
  ($payload['status']??null)==='success' &&
  is_array($payload['data']['response']??null) &&
  strtoupper(trim((string)($payload['data']['response']['status_text']??'')))==='CANCELLED';
}
function pfApiCallSubscription(string $token,string $action,string $method='GET'):array{
 if(!pfEnabled()||!preg_match('/^[a-f0-9-]{36}$/iD',$token))throw new DomainException('Invalid sandbox or subscription.');
 if(!in_array($action,['fetch','cancel'],true)||!in_array($method,['GET','PUT'],true))throw new DomainException('Unsupported PayFast action.');
 if(!function_exists('curl_init'))throw new RuntimeException('cURL unavailable.');
 $timestamp=gmdate('Y-m-d\TH:i:s+00:00');
 $headers=['merchant-id'=>cfg('PAYFAST_MERCHANT_ID'),'version'=>'v1','timestamp'=>$timestamp];
 $signature=pfApiSignature($headers);
 $url='https://api.payfast.co.za/subscriptions/'.$token.'/'.$action.(pfMode()==='sandbox'?'?testing=true':'');
 $h=curl_init($url);
 if($h===false)throw new RuntimeException('Unable to initialise PayFast API.');
 curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['merchant-id: '.$headers['merchant-id'],'version: v1','timestamp: '.$timestamp,'signature: '.$signature]]);
 $response=curl_exec($h);$http=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$error=curl_errno($h);curl_close($h);
 if($error!==0||!is_string($response))throw new RuntimeException('PayFast subscription API connection failed.');
 $payload=json_decode($response,true);
 return [$http,is_array($payload)?$payload:null];
}
/** API signature includes alphabetised headers AND the changed body variables. */
function pfApiSubscriptionUpdateSignature(array $headers,array $body):string{
 return pfApiSignature(array_merge($headers,$body));
}
function pfApiUpdateAmount(string $token,int $amount):bool{
 if(!pfEnabled()||(pfMode()==='live'&&cfg('PAID_BILLING_ENABLED','0')!=='1')||$amount<500||$amount>100000000||preg_match('/^[a-f0-9-]{36}$/iD',$token)!==1)throw new DomainException('Subscription update not permitted.');
 $timestamp=gmdate('Y-m-d\TH:i:s+00:00');
 $headers=['merchant-id'=>cfg('PAYFAST_MERCHANT_ID'),'version'=>'v1','timestamp'=>$timestamp];
 $body=['amount'=>(string)$amount];
 $signature=pfApiSubscriptionUpdateSignature($headers,$body);
 $url='https://api.payfast.co.za/subscriptions/'.$token.'/update'.(pfMode()==='sandbox'?'?testing=true':'');
 $h=curl_init($url);
 if($h===false)throw new RuntimeException('PayFast API unavailable.');
 curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_POSTFIELDS=>http_build_query($body,'','&',PHP_QUERY_RFC1738),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['merchant-id: '.$headers['merchant-id'],'version: v1','timestamp: '.$timestamp,'signature: '.$signature,'Content-Type: application/x-www-form-urlencoded']]);
 $response=curl_exec($h);$http=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$error=curl_errno($h);curl_close($h);
 if($error!==0||!is_string($response))throw new RuntimeException('PayFast update transport failed.');
 if($http<200||$http>=300)throw new RuntimeException('PayFast did not accept the subscription update (HTTP '.$http.').');
 // Confirm the provider state independently; only an active subscription at the agreed amount qualifies.
 [$status,$data]=pfApiCallSubscription($token,'fetch');
 return $status===200&&is_array($data)&&($data['status']??null)==='success'&&
  is_array($data['data']['response']??null)&&
  strtoupper((string)($data['data']['response']['status_text']??''))==='ACTIVE'&&
  (int)($data['data']['response']['amount']??-1)===$amount;
}
function pfApiCancel(string $token):bool{
 if(!pfEnabled()||!preg_match('/^[a-f0-9-]{36}$/iD',$token))return false;
 // Do not infer success from a cancellation endpoint response shape:
 // independently fetch the provider status before changing local records.
 pfApiCallSubscription($token,'cancel','PUT');
 [$http,$payload]=pfApiCallSubscription($token,'fetch');
 return pfApiSubscriptionCancelledResponse($http,$payload);
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
if($path==='/payfast/invoice.pdf'){
 $user=mustUser();$invoice=(int)($_GET['id']??0);
 if($invoice<1)throw new DomainException('Invalid payment record.');
 $q=db()->prepare('SELECT i.*,o.plan,o.mode,u.email,u.name FROM payfast_invoices i JOIN payfast_orders o ON o.id=i.order_id JOIN users u ON u.id=i.user_id WHERE i.id=? AND i.user_id=?');
 $q->execute([$invoice,$user['id']]);$receipt=$q->fetch();
 if(!$receipt){http_response_code(404);exit('Payment record not found.');}
 $autoload=dirname(__DIR__).'/vendor/autoload.php';if(!is_file($autoload))throw new RuntimeException('PDF library unavailable.');
 require_once $autoload;
 $fmt=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $isTest=$receipt['mode']==='sandbox';
 $kind='PAYMENT RECEIPT';
 $warning=$isTest?'SANDBOX TEST ONLY — NO REAL PAYMENT':'This payment receipt is not a VAT tax invoice.';
 $issuer=cfg('BILLING_LEGAL_NAME','FyndEt');
 $address=cfg('BILLING_BUSINESS_ADDRESS','');
 $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#152431;font-size:12px;padding:32px}h1{font-size:24px}.muted{color:#596d79}table{border-collapse:collapse;width:100%;margin-top:24px}th,td{padding:13px;text-align:left;border-bottom:1px solid #d4dde2}th{background:#f2f5f6}</style></head><body><h1>FyndEt · '.$kind.'</h1><p class="muted">'.$fmt($warning).'</p><p><strong>Issuer:</strong> '.$fmt($issuer).' '.$fmt($address).'</p><p><strong>Customer:</strong> '.$fmt($receipt['name']).' ('.$fmt($receipt['email']).')</p><p><strong>Reference:</strong> '.$fmt($receipt['invoice_number']).'<br><strong>Issued:</strong> '.$fmt($receipt['issued_at']).'<br><strong>PayFast payment:</strong> '.$fmt($receipt['payment_id']).'</p><table><tr><th>Description</th><th>Amount</th></tr><tr><td>FyndEt '.$fmt(ucfirst((string)$receipt['plan'])).' recurring subscription</td><td>ZAR '.$fmt(number_format((int)$receipt['amount_cents']/100,2)).'</td></tr></table><p><strong>Recorded status:</strong> '.$fmt($receipt['status']).'</p><p class="muted">Financial details must be confirmed against the PayFast merchant statement.</p></body></html>';
 $pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false]);$pdf->loadHtml($html);$pdf->setPaper('A4');$pdf->render();
 header('Content-Type: application/pdf');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="fyndet-payment-'.$invoice.'.pdf"');
 echo $pdf->output();exit;
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
