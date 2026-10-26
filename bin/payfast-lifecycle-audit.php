<?php
declare(strict_types=1);
/** Read-only operational reconciliation; never prints IDs, credentials or emails. */
if(PHP_SAPI!=='cli')exit(1);
$env=[];
foreach(file(dirname(__DIR__).'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
if(($env['PAYFAST_MODE']??'')!=='sandbox'||($env['PAYFAST_LIVE_APPROVED']??'0')!=='0'||($env['PAID_BILLING_ENABLED']??'0')!=='0')exit("FAIL: live gate state\n");
try{
 $pdo=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $queries=[
  'Completed sandbox orders'=>"SELECT COUNT(*) FROM payfast_orders WHERE mode='sandbox' AND status='complete'",
  'Active sandbox subscriptions'=>"SELECT COUNT(*) FROM payfast_subscriptions WHERE mode='sandbox' AND status='active'",
  'Sandbox COMPLETED ITNs'=>"SELECT COUNT(*) FROM payfast_notifications n JOIN payfast_orders o ON o.id=n.order_id WHERE o.mode='sandbox' AND n.payment_status='COMPLETE'",
  'Sandbox payment records'=>"SELECT COUNT(*) FROM payfast_invoices i JOIN payfast_orders o ON o.id=i.order_id WHERE o.mode='sandbox'",
  'Unlinked completed payment records'=>"SELECT COUNT(*) FROM payfast_invoices i JOIN payfast_orders o ON o.id=i.order_id LEFT JOIN payfast_notifications n ON n.order_id=i.order_id AND n.payment_id=i.payment_id AND n.payment_status='COMPLETE' WHERE o.mode='sandbox' AND n.id IS NULL",
  'Cancelled subscriptions'=>"SELECT COUNT(*) FROM payfast_subscriptions WHERE status='cancelled'",
  'Failed payment notices'=>"SELECT COUNT(*) FROM payfast_notifications WHERE payment_status='FAILED'",
  'Superseded sandbox orders'=>"SELECT COUNT(*) FROM payfast_orders WHERE mode='sandbox' AND status='superseded'",
 ];
 echo "FYNDET PAYFAST — LIFECYCLE AUDIT (READ ONLY)\n";
 foreach($queries as $name=>$sql){echo $name.': '.(int)$pdo->query($sql)->fetchColumn()."\n";}
 echo "NOTE: Renewal, failure and cancellation acceptance require real sandbox events; zero counts are not proof.\n";
}catch(Throwable $e){fwrite(STDERR,"FAIL: Lifecycle audit could not complete; inspect server diagnostics securely.\n");exit(1);}
