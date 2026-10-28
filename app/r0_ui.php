<?php
declare(strict_types=1);
require_once __DIR__.'/r0_storage.php';
function fyndetR0HandlePost(string $root,array $env):never {
 $u=mustUser();verifyCsrf();$db=db();
 if(!fyndetR0Enabled($root,$u)){http_response_code(403);exit('URL intake not enabled for this account');}
 $job=trim((string)($_POST['investigation_id']??''));
 $stmt=$db->prepare('SELECT id,target_email FROM investigations WHERE id=? AND user_id=?');$stmt->execute([$job,$u['id']]);$report=$stmt->fetch();
 if(!$report){http_response_code(404);exit('Investigation not found');}
 if(($_POST['lawful_purpose']??'')!=='1'){http_response_code(422);exit('Lawful-purpose declaration required');}
 $purpose=trim((string)($_POST['purpose']??''));
 if(mb_strlen($purpose)<10||mb_strlen($purpose)>400){http_response_code(422);exit('Purpose must contain 10–400 characters');}
 $raw=(string)($_POST['source_urls']??'');
 if(strlen($raw)>16000){http_response_code(422);exit('Submission too large');}
 $urls=array_values(array_filter(array_map('trim',preg_split('/\\r?\\n/',$raw)?:[])));
 if(!$urls || count($urls)>8){http_response_code(422);exit('Provide 1–8 URLs');}
 $unique=[];foreach($urls as $url){$canonical=fyndetR0Canonical($url);if(!$canonical){http_response_code(422);exit('Invalid or unsafe URL');}$unique[$canonical]=$url;}
 $uid=(int)$u['id'];$email=strtolower((string)$report['target_email']);
 $supp=$db->prepare('SELECT 1 FROM fyndet_url_suppression WHERE email_sha256=?');$supp->execute([hash('sha256',$email)]);
 if($supp->fetchColumn()){http_response_code(403);exit('This address has opted out');}
 $lock='fyndet_r0_'.$uid;$hold=$db->prepare('SELECT GET_LOCK(?,5)');$hold->execute([$lock]);if((int)$hold->fetchColumn()!==1){http_response_code(429);exit('Please try later');}
 try {
  $db->beginTransaction();
  $q=$db->prepare('SELECT COUNT(*) FROM fyndet_url_submissions WHERE investigation_id=?');$q->execute([$job]);$perJob=(int)$q->fetchColumn();
  $q=$db->prepare('SELECT COUNT(*) FROM fyndet_url_submissions WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');$q->execute([$uid]);$hourly=(int)$q->fetchColumn();
  if($perJob+count($unique)>20||$hourly+count($unique)>20){$db->rollBack();http_response_code(429);exit('URL quota exceeded');}
  $prior=$db->prepare('SELECT canonical_url FROM fyndet_url_submissions WHERE investigation_id=?');$prior->execute([$job]);$known=array_fill_keys($prior->fetchAll(PDO::FETCH_COLUMN),true);
  $q=$db->prepare('INSERT INTO fyndet_url_submissions(investigation_id,user_id,target_email,source_url,canonical_url) VALUES(?,?,?,?,?)');
  foreach($unique as $canonical=>$url){if(isset($known[$canonical]))continue;$known[$canonical]=true;$q->execute([$job,$uid,$email,$url,$canonical]);fyndetR0Audit($db,$uid,$job,(int)$db->lastInsertId(),'queued','Declared purpose: '.hash('sha256',$purpose));}
  $db->commit();
 } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 finally {$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}
 flash('URLs queued for source verification');go('app/report?id='.rawurlencode($job));
}
function fyndetR0ReportWidget(string $root,array $u,array $report,string $csrf):void {
 $email=(string)$report['target_email'];$id=(string)$report['id'];
 echo '<section class="panel" id="r0-intelligence"><h3>Public-source review — R0</h3><p>These checks are limited. Manual searches may find more public references. A mention is not proof of identity or account ownership.</p>';
 echo '<h4>Search manually</h4><p>Open a search engine, review results yourself, and paste relevant source URLs below. FyndEt never sends automated searches to these services.</p>';
 foreach(fyndetR0SearchLinks($email) as $link)echo '<a target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" href="'.htmlspecialchars($link['url'],ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($link['engine'].' — '.$link['type'],ENT_QUOTES,'UTF-8').'</a> &nbsp; ';
 echo '<h4>Discovery sections</h4>';
 foreach(['social_presence'=>'Social & online presence','web_presence'=>'Web presence','professional_associations'=>'Professional associations','security_exposure'=>'Security & exposure','community_activity'=>'Community activity','technical_associations'=>'Technical associations'] as $key=>$label){
  $state=$key==='security_exposure'?'not_checked':($key==='technical_associations'?'not_configured':'partial_coverage');
  echo '<p><strong>'.htmlspecialchars($label).'</strong>: '.htmlspecialchars($state).' — No broad automated discovery in R0; only approved manual source submissions and verified self-check sources are considered.</p>';
 }
 $db=db();$q=$db->prepare('SELECT source_url,status,disposition,reason,matched_snippet FROM fyndet_url_submissions WHERE investigation_id=? AND user_id=? ORDER BY id DESC LIMIT 20');$q->execute([$id,$u['id']]);
 echo '<h4>Submitted public URLs</h4>';
 foreach($q as $r){echo '<p><a rel="noopener noreferrer" referrerpolicy="no-referrer" target="_blank" href="'.htmlspecialchars($r['source_url'],ENT_QUOTES,'UTF-8').'">Source</a> — '.htmlspecialchars($r['disposition']?:$r['status']);if($r['reason'])echo ' ('.htmlspecialchars($r['reason']).')';if($r['matched_snippet'])echo '<p>'.htmlspecialchars($r['matched_snippet']).'</p>';echo '</p>';}
 if(fyndetR0Enabled($root,$u)){
  echo '<form method="post" action="/app/report/urls"><input type="hidden" name="_csrf" value="'.htmlspecialchars($csrf,ENT_QUOTES,'UTF-8').'"><input type="hidden" name="investigation_id" value="'.htmlspecialchars($id,ENT_QUOTES,'UTF-8').'"><label>Source URLs (one per line, maximum 8)<textarea name="source_urls" rows="5" maxlength="16000" required></textarea></label><label>Lawful purpose<textarea name="purpose" minlength="10" maxlength="400" required></textarea></label><label><input type="checkbox" name="lawful_purpose" value="1" required>I declare a lawful purpose and will respect the subject’s privacy and opt-out rights.</label><button type="submit">Queue URL verification</button></form>';
 } else echo '<p>URL intake is not enabled for this account.</p>';
 echo '</section>';
}
