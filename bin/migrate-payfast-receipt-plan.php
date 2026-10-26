<?php
declare(strict_types=1);
/** Idempotent migration for per-payment plan snapshots; preserves historic receipts. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
foreach(['PAYFAST_MODE'=>'sandbox','PAYFAST_LIVE_APPROVED'=>'0','PAID_BILLING_ENABLED'=>'0','PLATFORM_CURRENCY'=>'USD'] as $key=>$val){
 if(($env[$key]??'')!==$val){fwrite(STDERR,"FAIL: Sandbox migration safety gate $key\n");exit(1);}
}
try{
 $db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $col=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payfast_invoices' AND column_name='plan'")->fetchColumn();
 if((int)$col===0){
  $db->exec("ALTER TABLE payfast_invoices ADD COLUMN plan VARCHAR(20) NULL AFTER order_id");
  echo "PASS: Added per-payment receipt plan column\n";
 }else echo "PASS: Receipt plan column already exists\n";
 $db->exec("UPDATE payfast_invoices i INNER JOIN payfast_orders o ON o.id=i.order_id SET i.plan=o.plan WHERE i.plan IS NULL");
 $invalid=(int)$db->query("SELECT COUNT(*) FROM payfast_invoices WHERE plan IS NULL OR plan NOT IN ('starter','pro','business')")->fetchColumn();
 if($invalid>0)throw new RuntimeException('Unrecognised or incomplete receipt snapshots: '.$invalid);
 echo "PASS: Historical receipts backfilled without overwriting existing snapshots\n";
 echo "PASS: No invoice amounts or payment IDs changed\n";
}catch(Throwable $e){fwrite(STDERR,"FAIL: Receipt migration could not complete: ".$e->getMessage()."\n");exit(1);}
