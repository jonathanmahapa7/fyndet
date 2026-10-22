<?php
declare(strict_types=1);
/**
 * FyndEt scheduled investigation worker.
 * Run using cPanel PHP CLI cron, outside webroot.
 * Allowed lookup scope: DNS configuration of the domain for self-check email.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
 $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
require_once $root.'/app/providers.php';
require_once $root.'/app/discovery.php';
require_once $root.'/app/certificates.php';
require_once $root.'/app/federated_search.php';
foreach(['DB_HOST','DB_NAME','DB_USER'] as $key)if(empty($env[$key])){fwrite(STDERR,"Missing configuration: $key\n");exit(1);}
$dsn='mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT']??3306).';dbname='.$env['DB_NAME'].';charset=utf8mb4';
$db=new PDO($dsn,$env['DB_USER'],$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$lockPath=$root.'/var/worker.lock';if(!is_dir($root.'/var'))mkdir($root.'/var',0700,true);
$lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){echo "Another worker is running\n";exit(0);}
// Bounded work per invocation: avoid long-lived PHP cron processes on shared cPanel.
for($i=0;$i<2;$i++){
 $db->beginTransaction();
 $s=$db->query("SELECT id,user_id,target_email,purpose FROM investigations WHERE status='queued' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
 $job=$s->fetch();
 if(!$job){$db->commit();break;}
 $q=$db->prepare("UPDATE investigations SET status='running' WHERE id=?");$q->execute([$job['id']]);$db->commit();
 try{
  $domain=substr(strrchr($job['target_email'],'@'),1);
  if(!$domain||!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))throw new RuntimeException('Invalid domain.');
  // No login enumeration, private profile scraping, or email-existence probing.
  $result=['retrieved_at'=>gmdate('c'),'subject_scope'=>'registered_email_self_check','methodology'=>'Public DNS domain configuration only. These records do not prove individual account ownership.','records'=>[],'source'=>'DNS lookup via PHP dns_get_record'];
  foreach(['MX','TXT','NS'] as $type){
    $dns=@dns_get_record($domain,constant('DNS_'.$type));
    $rows=[];foreach(($dns?:[]) as $r){
      if($type==='MX')$rows[]=($r['target']??'').' (priority '.($r['pri']??'?').')';
      elseif($type==='TXT')$rows[]=mb_substr($r['txt']??'',0,350);
      else $rows[]=$r['target']??'';
      if(count($rows)>=15)break;
    }$result['records'][$type]=$rows;
  }
  // Provider requests are allowed only for the registered email after verified ownership.
  $verify=$db->prepare('SELECT email,email_verified_at FROM users WHERE id=?');$verify->execute([$job['user_id']]);$account=$verify->fetch();
  $authorised=$account && !empty($account['email_verified_at']) && hash_equals(strtolower($account['email']),strtolower($job['target_email']));
  if($authorised){
    $result['public_references']=publicCommitEvidence(strtolower($job['target_email']),$env);
    $result['breach_exposure']=licensedBreachEvidence(strtolower($job['target_email']),$env);
    $result['expanded_discovery']=fyndetRunExtendedDiscovery(strtolower($job['target_email']),$env);
    $result['expanded_discovery']['sources']['certificate_transparency']=fyndetCertificateEvidence(strtolower($job['target_email']),$env);
    foreach(['public_documents','professional_references','community_references'] as $sourceCategory){
      $result['expanded_discovery']['sources'][$sourceCategory]=fyndetFederatedSearch(strtolower($job['target_email']),$env,$sourceCategory);
    }
    $result['evidence_summary']=fyndetCorrelate(['public_references'=>$result['public_references'],'breach_exposure'=>$result['breach_exposure']]+$result['expanded_discovery']['sources']);
  } else {
    $result['public_references']=['state'=>'not_checked','reason'=>'Email control not verified','items'=>[]];
    $result['breach_exposure']=['state'=>'not_checked','reason'=>'Email control not verified','items'=>[]];
  }
  $q=$db->prepare("UPDATE investigations SET status='complete',result_json=?,finished_at=NOW() WHERE id=?");
  $q->execute([json_encode($result,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),$job['id']]);
  if(str_starts_with((string)$job['purpose'],'Scheduled authorised monitoring')){
    $monitor=$db->prepare('SELECT last_digest FROM monitoring_profiles WHERE user_id=?');
    $monitor->execute([$job['user_id']]);$profile=$monitor->fetch();
    if($profile){
      $fingerprint=['dns'=>$result['records']??[],'sections'=>[]];
      foreach(['public_references','breach_exposure'] as $key){
        $section=$result[$key]??[];
        if(!in_array($section['state']??'', ['observed','not_found'],true))continue;
        foreach(($section['items']??[]) as $item){
          $fingerprint['sections'][$key][]=[$item['type']??'', $item['source_url']??$item['name']??$item['repository']??''];
        }
      }
      foreach(($result['expanded_discovery']['sources']??[]) as $key=>$section){
        if(!in_array($section['state']??'', ['observed','not_found'],true))continue;
        foreach(($section['items']??[]) as $item){
          $fingerprint['sections'][$key][]=[$item['type']??'', $item['source_url']??$item['name']??$item['value']??''];
        }
      }
      foreach($fingerprint['sections'] as &$values)sort($values);unset($values);
      ksort($fingerprint['sections']);
      $digest=hash('sha256',json_encode($fingerprint,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE));
      $kind=empty($profile['last_digest'])?'first_scan':($profile['last_digest']===$digest?'unchanged':'changed');
      $q=$db->prepare('INSERT INTO monitoring_events(user_id,investigation_id,event_type,observed_digest) VALUES(?,?,?,?)');
      $q->execute([$job['user_id'],$job['id'],$kind,$digest]);
      $q=$db->prepare("UPDATE monitoring_profiles SET last_digest=?,last_changed_at=IF(?='changed',UTC_TIMESTAMP(),last_changed_at) WHERE user_id=?");
      $q->execute([$digest,$kind,$job['user_id']]);
    }
  }
  echo "Completed ".$job['id']."\n";
 }catch(Throwable $e){
  error_log('FyndEt worker '.$job['id'].': '.$e->getMessage());
  $q=$db->prepare("UPDATE investigations SET status='failed',error_message='An investigation processing error occurred.',finished_at=NOW() WHERE id=?");$q->execute([$job['id']]);
 }
}
flock($lock,LOCK_UN);fclose($lock);
