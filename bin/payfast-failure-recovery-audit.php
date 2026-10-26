<?php
declare(strict_types=1);
/** Read-only failure/recovery report. NEVER changes entitlements. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
foreach(['PAYFAST_MODE'=>'sandbox','PAYFAST_LIVE_APPROVED'=>'0','PAID_BILLING_ENABLED'=>'0','PLATFORM_CURRENCY'=>'USD'] as $key=>$value){
 if(($env[$key]??'')!==$value){fwrite(STDERR,"FAIL: Billing safety configuration\n");exit(1);}
}
try{
 $db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $sql="SELECT n.order_id,n.payment_id,n.received_at,
  EXISTS(SELECT 1 FROM payfast_notifications c WHERE c.order_id=n.order_id AND c.payment_status='COMPLETE' AND c.received_at>n.received_at) AS later_complete
  FROM payfast_notifications n JOIN payfast_orders o ON o.id=n.order_id
  WHERE o.mode='sandbox' AND n.payment_status='FAILED' ORDER BY n.received_at DESC";
 $q=$db->query($sql);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $recovered=0;$open=0;
 foreach($rows as $row){if((int)$row['later_complete']===1)$recovered++;else $open++;}
 echo "FYNDET — REAL FAILED RENEWAL RECONCILIATION\n";
 echo "Provider failed-payment notices: ".count($rows)."\n";
 echo "Notices with later completed payment: $recovered\n";
 echo "Unresolved notices (review required): $open\n";
 echo "This report does not assert that a later payment covers the same billing cycle.\n";
 echo "No fabricated payment, retries, entitlement changes or live charges.\n";
 if($open>0)exit(2);
}catch(Throwable $e){fwrite(STDERR,"FAIL: Failed-payment report unavailable.\n");exit(1);}
