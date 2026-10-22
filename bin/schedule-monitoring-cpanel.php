<?php
declare(strict_types=1);
/**
 * Once daily cPanel Cron:
 * php bin/schedule-monitoring-cpanel.php
 * Enqueues at most 15 verified Pro/Business personal rechecks per invocation,
 * preserving shared monthly usage credits.
 */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
if(!is_file($root.'/.env'))exit(1);
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$env[trim($key)]=trim($value," \t\n\r\0\x0B\"'");}
require_once $root.'/app/discovery.php';
$db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??3306).';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if(!is_dir($root.'/var'))mkdir($root.'/var',0700,true);
$handle=fopen($root.'/var/monitoring.lock','c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))exit(0);
$now=gmdate('Y-m');
$candidates=$db->query("SELECT p.user_id FROM monitoring_profiles p WHERE p.enabled=1 AND (p.last_queued_at IS NULL OR p.last_queued_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL p.interval_days DAY)) ORDER BY COALESCE(p.last_queued_at,'2000-01-01') ASC LIMIT 15")->fetchAll(PDO::FETCH_COLUMN);
$queued=0;
foreach($candidates as $candidate){
 $uid=(int)$candidate;$db->beginTransaction();
 try{
  $q=$db->prepare("SELECT p.enabled,p.interval_days,p.last_queued_at,u.email,u.email_verified_at,u.plan,u.subscription_status FROM monitoring_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=? FOR UPDATE");
  $q->execute([$uid]);$row=$q->fetch();
  if(!$row||!(int)$row['enabled']||empty($row['email_verified_at'])||($row['last_queued_at']&&strtotime($row['last_queued_at'])>time()-(int)$row['interval_days']*86400)){$db->rollBack();continue;}
  $organisation=$db->prepare("SELECT o.owner_user_id FROM organisation_members m JOIN organisations o ON o.id=m.organisation_id JOIN users owner ON owner.id=o.owner_user_id WHERE m.user_id=? AND owner.plan='business' AND owner.subscription_status IN ('active','trialing') LIMIT 1");
  $organisation->execute([$uid]);$ownerId=(int)($organisation->fetchColumn()?:0);
  $lock=$db->prepare('SELECT plan,subscription_status FROM users WHERE id=? FOR UPDATE');$lock->execute([$ownerId?:$uid]);$planRow=$lock->fetch();
  $plan=$ownerId?'business':($planRow['plan']??'free');
  if(!in_array($plan,['pro','business'],true)){$db->rollBack();continue;}
  $limit=$plan==='business'?250:100;
  if($ownerId){
   $credits=$db->prepare('SELECT COUNT(*) FROM usage_ledger l JOIN organisation_members m ON m.user_id=l.user_id WHERE m.organisation_id=(SELECT organisation_id FROM organisation_members WHERE user_id=? LIMIT 1) AND l.period=?');$credits->execute([$uid,$now]);
  }else{$credits=$db->prepare('SELECT COUNT(*) FROM usage_ledger WHERE user_id=? AND period=?');$credits->execute([$uid,$now]);}
  if((int)$credits->fetchColumn()>=$limit){$db->rollBack();continue;}
  $id=bin2hex(random_bytes(16));
  $q=$db->prepare('INSERT INTO investigations(id,user_id,target_email,purpose) VALUES(?,?,?,?)');$q->execute([$id,$uid,$row['email'],'Scheduled authorised monitoring of my verified email footprint']);
  $q=$db->prepare('INSERT INTO usage_ledger(user_id,investigation_id,period) VALUES(?,?,?)');$q->execute([$uid,$id,$now]);
  $q=$db->prepare('UPDATE monitoring_profiles SET last_queued_at=UTC_TIMESTAMP() WHERE user_id=?');$q->execute([$uid]);
  $db->commit();$queued++;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('FyndEt monitoring scheduler: '.$e->getMessage());}
}
flock($handle,LOCK_UN);fclose($handle);echo "Monitoring scheduled: $queued\n";
