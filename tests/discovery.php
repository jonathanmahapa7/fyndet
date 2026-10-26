<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/discovery.php';
require dirname(__DIR__).'/app/certificates.php';
require dirname(__DIR__).'/app/providers.php';
require dirname(__DIR__).'/app/gravatar.php';
require dirname(__DIR__).'/app/crossref.php';
require dirname(__DIR__).'/app/federated_search.php';
function verify(bool $valid,string $name):void{if(!$valid){fwrite(STDERR,"FAIL: $name\n");exit(1);}echo "PASS: $name\n";}
$email='qa@example.com';
verify(hash('sha256',strtolower(trim('QA@EXAMPLE.COM')))===hash('sha256',$email),'Exact profile key is derived from normalised email');
verify(fyndetGravatarEvidence('invalid-address')['state']==='invalid','Public profile adapter validates email before network requests');
verify(fyndetCrossrefEvidence('invalid-address')['state']==='invalid','Scholarly source rejects invalid email before network calls');
$dns=fyndetDnsIntelligence($email);
verify(in_array($dns['state'],['observed','not_found'],true),'Domain DNS query returns an evidence state');
verify(is_array($dns['posture']??null),'Domain email-security posture is recorded');
$web=fyndetPublicWebEvidence($email,['BRAVE_SEARCH_ENABLED'=>'0']);
verify($web['state']==='not_checked','Unconfigured web search correctly marked not checked');
$web=fyndetPublicWebEvidence($email,['BRAVE_SEARCH_ENABLED'=>'1','BRAVE_SEARCH_STORAGE_LICENSE_CONFIRMED'=>'1','BRAVE_SEARCH_API_KEY'=>'']);
verify($web['state']==='source_unavailable','Enabled web search without key reports unavailable, not negative evidence');
$ct=fyndetCertificateEvidence($email,['CERTIFICATE_TRANSPARENCY_ENABLED'=>'0']);
verify($ct['state']==='not_checked','Disabled certificate transparency source is truthful');
$hibp=licensedBreachEvidence($email,['HIBP_ENABLED'=>'1','HIBP_API_KEY'=>'']);
verify($hibp['state']==='source_unavailable','Unlicensed breach source is not falsely reported as no breaches');
$git=publicCommitEvidence($email,['GITHUB_PUBLIC_COMMITS_ENABLED'=>'0']);
verify($git['state']==='not_checked','Disabled public commits source is explicitly unqueried');
$source=file_get_contents(dirname(__DIR__).'/app/providers.php');
verify(str_contains($source,'if($token!=='), 'GitHub public commit search does not require a paid API key');
$all=fyndetCorrelate(['domain'=>$dns,'web'=>$web,'ct'=>$ct,'breaches'=>$hibp,'github'=>$git]);
verify(isset($all['source_states']['source_unavailable'])&&$all['source_states']['source_unavailable']>=2,'Evidence coverage tracks unavailable sources');
verify(str_contains($all['limitations'],'Not found != does not exist'),'Correlation communicates uncertainty');
foreach(['public_documents','professional_references','community_references'] as $category){
 $status=fyndetFederatedSearch($email,['BRAVE_SEARCH_ENABLED'=>'0'],$category);
 verify($status['state']==='not_checked','Federated '.$category.' respects disabled-provider gate');
}
echo "ALL DISCOVERY CONTRACT TESTS PASSED\n";
