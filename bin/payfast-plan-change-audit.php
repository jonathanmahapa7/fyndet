<?php
declare(strict_types=1);
/** Read-only end-to-end PayFast plan-change and renewal acceptance summary. */
if(PHP_SAPI!=='cli')exit(1);
$env=[];
foreach(file(dirname(__DIR__).'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
foreach(['PAYFAST_MODE'=>'sandbox','PAYFAST_LIVE_APPROVED'=>'0','PAID_BILLING_ENABLED'=>'0','PLATFORM_CURRENCY'=>'USD'] as $k=>$v){
 if(($env[$k]??'')!==$v){fwrite(STDERR,"FAIL: safety gate ".$k."\n");exit(1);}
}
try{
 $db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $checks=[
  'Awaiting customer agreement'=>"SELECT COUNT(*) FROM payfast_plan_changes WHERE status='awaiting_review'",
  'Provider confirmed; awaiting verified renewal'=>"SELECT COUNT(*) FROM payfast_plan_changes WHERE status='provider_confirmed'",
  'Changes applied after genuine renewal'=>"SELECT COUNT(*) FROM payfast_plan_changes WHERE status='applied'",
  'Withdrawn requests'=>"SELECT COUNT(*) FROM payfast_plan_changes WHERE status='withdrawn'",
  'Plan mismatch after applied change'=>"SELECT COUNT(*) FROM payfast_plan_changes c JOIN payfast_subscriptions s ON s.user_id=c.user_id AND s.order_id=c.order_id WHERE c.status='applied' AND s.plan<>c.to_plan AND c.id=(SELECT MAX(x.id) FROM payfast_plan_changes x WHERE x.order_id=c.order_id AND x.status='applied')",
  'Completed notifications without matching receipts'=>"SELECT COUNT(*) FROM payfast_notifications n JOIN payfast_orders o ON o.id=n.order_id LEFT JOIN payfast_invoices i ON i.payment_id=n.payment_id AND i.order_id=n.order_id WHERE o.mode='sandbox' AND n.payment_status='COMPLETE' AND i.id IS NULL"
 ];
 echo "FYNDET — PLAN CHANGE / RENEWAL RELEASE AUDIT\n";
 $faults=0;
 foreach($checks as $name=>$sql){
  $count=(int)$db->query($sql)->fetchColumn();
  echo $name.': '.$count."\n";
  if(str_starts_with($name,'Plan mismatch')||str_starts_with($name,'Completed notifications'))$faults+=$count;
 }
 echo $faults===0?"PASS: No detected local reconciliation exception\n":"FAIL: Billing reconciliation exception needs review\n";
 echo "This report does not simulate PayFast events or verify merchant approval.\n";
 if($faults)exit(1);
}catch(Throwable $e){fwrite(STDERR,"FAIL: Could not run safe plan-change audit.\n");exit(1);}
