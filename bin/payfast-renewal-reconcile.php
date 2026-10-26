<?php
declare(strict_types=1);
/** Read-only renewal acceptance audit. Requires genuine signed provider ITNs. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
foreach(['PAYFAST_MODE'=>'sandbox','PAYFAST_LIVE_APPROVED'=>'0','PAID_BILLING_ENABLED'=>'0'] as $k=>$v){
 if(($env[$k]??'')!==$v){fwrite(STDERR,"FAIL: Unsafe gate ".$k."\n");exit(1);}
}
try{
 $db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$db->query("SELECT o.id,o.status,o.amount_cents,o.payment_id,COUNT(DISTINCT CASE WHEN n.payment_status='COMPLETE' THEN n.payment_id END) AS completed_events,
 COUNT(DISTINCT i.payment_id) AS receipts,
 COUNT(DISTINCT CASE WHEN n.payment_status='FAILED' THEN n.id END) AS failures
 FROM payfast_orders o
 LEFT JOIN payfast_notifications n ON n.order_id=o.id
 LEFT JOIN payfast_invoices i ON i.order_id=o.id
 WHERE o.mode='sandbox' AND o.status='complete'
 GROUP BY o.id,o.status,o.amount_cents,o.payment_id
 ORDER BY o.created_at DESC");
 $orders=$q->fetchAll();
 echo "FYNDET — REAL PAYFAST RENEWAL RECONCILIATION\n";
 $renewalCount=0;$exceptions=0;
 foreach($orders as $o){
  $events=(int)$o['completed_events'];$receipts=(int)$o['receipts'];
  $renewals=max(0,$events-1);$renewalCount+=$renewals;
  // Re-check amount and notification correspondence per independent payment ID.
  $check=$db->prepare("SELECT COUNT(*) FROM payfast_invoices i LEFT JOIN payfast_notifications n ON n.order_id=i.order_id AND n.payment_id=i.payment_id AND n.payment_status='COMPLETE' WHERE i.order_id=? AND (i.currency<>'ZAR' OR n.id IS NULL OR (i.amount_cents<>? AND NOT EXISTS (SELECT 1 FROM payfast_plan_changes c WHERE c.order_id=i.order_id AND c.quoted_zar_cents=i.amount_cents AND c.status IN ('provider_confirmed','applied'))))");
  $check->execute([$o['id'],$o['amount_cents']]);
  $bad=(int)$check->fetchColumn();
  if($bad!==0||$receipts!==$events)$exceptions++;
  echo 'Completed events: '.$events.' | receipts: '.$receipts.' | real renewals: '.$renewals.' | failed notices: '.(int)$o['failures'].' | reconciliation: '.($bad===0&&$events===$receipts?'PASS':'REVIEW')."\n";
 }
 echo 'Real renewal events: '.$renewalCount."\n";
 echo 'Order-level exceptions: '.$exceptions."\n";
 echo 'Live billing: DISABLED. No records changed.\n';
 if($renewalCount===0)echo "NOT YET TESTED: No subsequent genuine recurring payment notification found.\n";
 if($exceptions>0)exit(1);
}catch(Throwable $e){fwrite(STDERR,"FAIL: Renewal audit unavailable; inspect server errors securely.\n");exit(1);}
