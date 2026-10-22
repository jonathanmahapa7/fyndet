<?php
declare(strict_types=1);
/**
 * FyndEt extended discovery; self-check only after email ownership verification.
 * Never tests whether a target has an account by probing platform sign-in/reset endpoints.
 * All external providers opt-in, documented, bounded and explicitly attributed.
 */
function fyndetDiscoveryResult(string $state,string $reason,array $items=[]):array {
 return ['state'=>$state,'reason'=>$reason,'items'=>$items,'checked_at'=>gmdate('c')];
}
function fyndetDnsIntelligence(string $email):array {
 $domain=substr(strrchr($email,'@')?:'','@'=== ''?0:1);
 if(!$domain||!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return fyndetDiscoveryResult('invalid','Invalid subject domain');
 $findings=[];$types=['A'=>DNS_A,'AAAA'=>DNS_AAAA,'MX'=>DNS_MX,'NS'=>DNS_NS,'CAA'=>defined('DNS_CAA')?DNS_CAA:0,'TXT'=>DNS_TXT];
 foreach($types as $label=>$constant){
  if(!$constant)continue;
  $records=@dns_get_record($domain,$constant)?:[];
  foreach(array_slice($records,0,12) as $row){
   $value=match($label){
    'A','AAAA'=>(string)($row['ip']??$row['ipv6']??''),
    'MX'=>(string)($row['target']??''),
    'NS'=>(string)($row['target']??''),
    'CAA'=>(string)($row['value']??''),
    default=>(string)($row['txt']??'')
   };
   if($value==='')continue;
   $findings[]=['type'=>'domain_dns_record','record_type'=>$label,'value'=>mb_substr($value,0,350),'source'=>'Public DNS','collected_at'=>gmdate('c'),'confidence'=>'observed','caution'=>'Domain infrastructure evidence does not establish identity or individual account ownership.'];
  }
 }
 foreach(['_dmarc.'.$domain=>'DMARC','_mta-sts.'.$domain=>'MTA-STS','_smtp._tls.'.$domain=>'TLS-RPT'] as $name=>$label){
  foreach(array_slice(@dns_get_record($name,DNS_TXT)?:[],0,4) as $row){
   $value=(string)($row['txt']??'');if($value==='')continue;
   $findings[]=['type'=>'domain_email_security','record_type'=>$label,'value'=>mb_substr($value,0,350),'source'=>'Public DNS','collected_at'=>gmdate('c'),'confidence'=>'observed','caution'=>'A published policy is not proof the sender or recipient is secure.'];
  }
 }
 $spf=false;$dmarc=false;$mx=false;
 foreach($findings as $entry){
  if($entry['record_type']==='MX')$mx=true;
  if($entry['record_type']==='TXT'&&str_starts_with(strtolower($entry['value']),'v=spf1'))$spf=true;
  if($entry['record_type']==='DMARC'&&str_starts_with(strtolower($entry['value']),'v=dmarc1'))$dmarc=true;
 }
 return ['state'=>$findings?'observed':'not_found','reason'=>'Domain DNS configuration checked; never interpreted as proof an account exists','items'=>$findings,'posture'=>['mx_published'=>$mx,'spf_published'=>$spf,'dmarc_published'=>$dmarc],'checked_at'=>gmdate('c')];
}
function fyndetPublicWebEvidence(string $email,array $env):array {
 if(($env['BRAVE_SEARCH_ENABLED']??'0')!=='1')return fyndetDiscoveryResult('not_checked','Public web search adapter disabled pending approved API access');
 $key=(string)($env['BRAVE_SEARCH_API_KEY']??'');
 if($key==='')return fyndetDiscoveryResult('source_unavailable','Brave Search API credential not configured');
 if(!function_exists('curl_init'))return fyndetDiscoveryResult('source_unavailable','PHP cURL extension unavailable');
 $q='"'.$email.'"';
 $url='https://api.search.brave.com/res/v1/web/search?'.http_build_query(['q'=>$q,'count'=>20,'extra_snippets'=>'false']);
 $h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>16,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json','X-Subscription-Token: '.$key],CURLOPT_USERAGENT=>'FyndEt/1.0 (authorised self-check; no account enumeration)',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if(!is_string($body)||strlen($body)>800000||$status!==200)return fyndetDiscoveryResult('source_unavailable','Search API not available; response not treated as no-match');
 $json=json_decode($body,true);
 if(!is_array($json))return fyndetDiscoveryResult('source_unavailable','Search provider returned invalid data');
 $items=[];
 foreach(($json['web']['results']??[]) as $row){
  if(!is_array($row))continue;
  $title=trim(strip_tags((string)($row['title']??'')));$snippet=trim(strip_tags((string)($row['description']??'')));
  $link=(string)($row['url']??'');
  if(!preg_match('~^https://~i',$link)||filter_var($link,FILTER_VALIDATE_URL)===false)continue;
  // Snippet exact-email mention required; otherwise report only inconclusive source results.
  if(!str_contains(strtolower($title.' '.$snippet),$email))continue;
  $items[]=['type'=>'public_web_reference','source'=>'Brave Search API — indexed snippet','source_url'=>$link,'title'=>mb_substr($title,0,160),'summary'=>mb_substr($snippet,0,360),'match_basis'=>'Exact email string observed in indexed title or snippet','confidence'=>'indexed_exact_string','collected_at'=>gmdate('c'),'caution'=>'Search snippet is not verified site content and does not prove identity or account ownership.'];
  if(count($items)>=20)break;
 }
 return fyndetDiscoveryResult($items?'observed':'not_found','Limited authorised indexed web search; source results may be incomplete or outdated',$items);
}
function fyndetCorrelate(array $sections):array {
 $refs=[];$counts=['observed'=>0,'not_found'=>0,'not_checked'=>0,'source_unavailable'=>0,'other'=>0];
 foreach($sections as $key=>$section){
  $state=(string)($section['state']??'other');if(!isset($counts[$state]))$state='other';$counts[$state]++;
  foreach(($section['items']??[]) as $item){
   if(!is_array($item))continue;
   $reference=(string)($item['source_url']??$item['source']??$key);
   $signature=hash('sha256',$key.'|'.$reference.'|'.json_encode($item,JSON_UNESCAPED_SLASHES));
   $refs[$signature]=['section'=>$key,'source'=>$reference,'type'=>(string)($item['type']??'observation')];
  }
 }
 return ['checked_at'=>gmdate('c'),'source_states'=>$counts,'observations'=>count($refs),'confidence_policy'=>'Exact string observations are not account verification. Correlations do not establish a person’s identity.','limitations'=>'Every source is finite and access-controlled. Not found != does not exist.'];
}
function fyndetRunExtendedDiscovery(string $email,array $env):array {
 $sections=[
  'domain_intelligence'=>fyndetDnsIntelligence($email),
  'public_web'=>fyndetPublicWebEvidence($email,$env),
 ];
 return ['sources'=>$sections,'correlation'=>fyndetCorrelate($sections)];
}
