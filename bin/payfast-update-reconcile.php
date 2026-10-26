<?php
declare(strict_types=1);
/** Reconcile interrupted PayFast amount updates. Safe: fetch only, no PATCH. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
 $line=trim($line);
 if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim(trim($v),chr(34).chr(39));
}
function cfg(string $key,string $default=''):string {global $env;return (string)($env[$key]??$default);}
function pfMode():string{return cfg('PAYFAST_MODE','sandbox');}
function pfEnabled():bool{return cfg('PAYFAST_ENABLED','0')==='1'&&pfMode()==='sandbox'&&cfg('PAYFAST_MERCHANT_ID')!==''&&cfg('PAYFAST_MERCHANT_KEY')!==''&&cfg('PAYFAST_PASSPHRASE')!=='';}
foreach(['PAYFAST_MODE'=>'sandbox','PAYFAST_LIVE_APPROVED'=>'0','PAID_BILLING_ENABLED'=>'0','PLATFORM_CURRENCY'=>'USD'] as $k=>$v){
 if(cfg($k)!==$v){fwrite(STDERR,"FAIL: safety gate $k\n");exit(1);}
}
if(!pfEnabled()){fwrite(STDERR,"FAIL: Sandbox merchant configuration unavailable.\n");exit(1);}
$path='';require_once $root.'/app/payfast_manage.php';
try{
 $db=new PDO('mysql:host='.cfg('DB_HOST','localhost').';port='.cfg('DB_PORT','3306').';dbname='.cfg('DB_NAME').';charset=utf8mb4',cfg('DB_USER'),cfg('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$db->query("SELECT c.id,c.user_id,c.order_id,c.quoted_zar_cents,s.token,s.plan,s.status AS subscription_status,s.mode FROM payfast_plan_changes c JOIN payfast_subscriptions s ON s.user_id=c.user_id AND s.order_id=c.order_id WHERE c.status='updating' ORDER BY c.id LIMIT 100");
 $rows=$q->fetchAll();$confirmed=0;$unresolved=0;
 echo "FYNDET — INTERRUPTED PROVIDER UPDATE RECONCILIATION\n";
 foreach($rows as $row){
  $id=(int)$row['id'];
  if($row['mode']!=='sandbox'||$row['subscription_status']!=='active'||!preg_match('/^[a-f0-9-]{36}$/iD',(string)$row['token'])){
   echo "REVIEW: Request #$id subscription unavailable\n";$unresolved++;continue;
  }
  try{
   [$http,$payload]=pfApiCallSubscription((string)$row['token'],'fetch');
   $provider=$payload['data']['response']??null;
   $ok=$http===200&&($payload['status']??'')==='success'&&is_array($provider)&&strtoupper((string)($provider['status_text']??''))==='ACTIVE'&&(int)($provider['amount']??-1)===(int)$row['quoted_zar_cents'];
   if(!$ok){echo "REVIEW: Request #$id provider amount/status not confirmed\n";$unresolved++;continue;}
   $db->beginTransaction();
   try{
    $up=$db->prepare("UPDATE payfast_plan_changes SET status='provider_confirmed' WHERE id=? AND user_id=? AND order_id=? AND status='updating'");
    $up->execute([$id,$row['user_id'],$row['order_id']]);
    $db->commit();
    if($up->rowCount()===1){$confirmed++;echo "PASS: Request #$id independently confirmed\n";}
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }catch(Throwable $e){echo "REVIEW: Request #$id independent fetch unavailable\n";$unresolved++;}
 }
 echo "Provider-confirmed requests: $confirmed\n";
 echo "Unresolved requests: $unresolved\n";
 echo "No new payment or PATCH requests initiated.\n";
 if($unresolved>0)exit(2);
}catch(Throwable $e){fwrite(STDERR,"FAIL: Reconciliation could not run.\n");exit(1);}
