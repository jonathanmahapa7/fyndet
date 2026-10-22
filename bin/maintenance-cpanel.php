<?php
declare(strict_types=1);
/**
 * Run daily with cPanel Cron; private logs only.
 * Deletes expired investigative results and invalidated tokens.
 */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");}
$db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??3306).';dbname='.($env['DB_NAME']??'').';charset=utf8mb4',$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$lock=fopen($root.'/var/maintenance.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){echo "Another maintenance run is active\n";exit(0);}
$db->exec("INSERT IGNORE INTO maintenance_runs(job_name,last_started_at) VALUES('daily',UTC_TIMESTAMP())");
$db->exec("UPDATE maintenance_runs SET last_started_at=UTC_TIMESTAMP(),last_error=NULL WHERE job_name='daily'");
try{
 $db->beginTransaction();
 foreach(['email_verification_tokens','password_reset_tokens','organisation_invites'] as $table)$db->exec("DELETE FROM $table WHERE expires_at<UTC_TIMESTAMP()");
 $db->exec("DELETE FROM auth_rate_limits WHERE window_ends<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)");
 $db->exec("DELETE FROM investigations WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)");
 // Purge investigation records based on owning user's plan; leave ledger minimal for billing integrity.
 $days=['free'=>7,'starter'=>30,'pro'=>90,'business'=>180];
 $q=$db->prepare('DELETE i FROM investigations i JOIN users u ON i.user_id=u.id WHERE u.plan=? AND i.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY)');
 foreach($days as $plan=>$retention)$q->execute([$plan,$retention]);
 $db->exec("DELETE FROM audit_events WHERE occurred_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 730 DAY)");
 $db->exec("DELETE FROM billing_events WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 730 DAY)");
 $db->exec("UPDATE maintenance_runs SET last_succeeded_at=UTC_TIMESTAMP(),last_error=NULL WHERE job_name='daily'");
 $db->commit();echo "Maintenance complete\n";
}catch(Throwable $e){
 if($db->inTransaction())$db->rollBack();
 $error=substr($e->getMessage(),0,400);
 $q=$db->prepare("UPDATE maintenance_runs SET last_error=? WHERE job_name='daily'");$q->execute([$error]);
 error_log('FyndEt maintenance: '.$error);exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
