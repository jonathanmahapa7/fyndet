<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require_once $root.'/app/r0_storage.php';
$env=[];foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if(!$line||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\r\n\"'");}
foreach(['DB_HOST','DB_NAME','DB_USER'] as $k)if(empty($env[$k]))throw new RuntimeException('Database config incomplete');
$db=new PDO('mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT']??3306).';dbname='.$env['DB_NAME'].';charset=utf8mb4',$env['DB_USER'],$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$flags=fyndetR0Flags($root);if(($flags['FYNDET_URL_INTAKE_ENABLED']??'0')!=='1'){echo "R0 URL intake disabled\n";exit;}
$lock=fopen($root.'/var/r0-intake.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$jobs=$db->query("SELECT * FROM fyndet_url_submissions WHERE status='queued' ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach($jobs as $job){
 $id=(int)$job['id'];$q=$db->prepare("UPDATE fyndet_url_submissions SET status='processing' WHERE id=? AND status='queued'");$q->execute([$id]);if(!$q->rowCount())continue;
 $user=(int)$job['user_id'];$email=strtolower((string)$job['target_email']);
 $policy=$db->prepare('SELECT 1 FROM fyndet_url_suppression WHERE email_sha256=?');$policy->execute([hash('sha256',$email)]);
 try{$v=$policy->fetchColumn()?['state'=>'blocked','reason'=>'subject_suppressed']:fyndetR0Verify($email,(string)$job['source_url']);}catch(Throwable $error){$v=['state'=>'user_reported','reason'=>'verification_exception'];}
 $reason=substr((string)($v['reason']??'unspecified'),0,160);
 if(($v['state']??'')==='content_verified' && !empty($v['content_hash'])){$dup=$db->prepare("SELECT 1 FROM fyndet_url_submissions WHERE id<>? AND investigation_id=? AND content_hash=? AND disposition='content_verified' LIMIT 1");$dup->execute([$id,$job['investigation_id'],$v['content_hash']]);if($dup->fetchColumn()){$v=['state'=>'duplicate_content','reason'=>'matching_public_content_already_recorded'];$reason='matching_public_content_already_recorded';}}
 $state=(string)($v['state']??'user_reported');
 $q=$db->prepare("UPDATE fyndet_url_submissions SET status='complete',disposition=?,reason=?,http_status=?,matched_snippet=?,content_hash=?,processed_at=UTC_TIMESTAMP() WHERE id=?");
 $q->execute([$state,$reason,$v['http_status']??null,$v['snippet']??null,$v['content_hash']??null,$id]);
 fyndetR0Audit($db,$user,(string)$job['investigation_id'],$id,'verified',$state.':'.$reason);
 echo 'URL '.(int)$id.' '.$state.' '.$reason."\n";
}
