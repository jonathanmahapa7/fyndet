<?php
declare(strict_types=1);
/**
 * Optional, source-attributed third-party adapters.
 * Called only from the authenticated, self-check-only queue worker.
 * Never scrape registration/password-reset pages or obtain secrets.
 */
function safeGet(string $url,array $headers=[]):array {
 if(!function_exists('curl_init'))return ['status'=>0,'data'=>null,'error'=>'cURL unavailable'];
 $host=parse_url($url,PHP_URL_HOST);
 if(!in_array($host,['api.github.com','haveibeenpwned.com'],true))return ['status'=>0,'data'=>null,'error'=>'Host not allowed'];
 $h=curl_init($url);
 curl_setopt_array($h,[
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
  CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>14,
  CURLOPT_HTTPHEADER=>$headers,
  CURLOPT_USERAGENT=>'FyndEt/1.0 (self-check digital footprint; admin contact required)',
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXREDIRS=>0,
 ]);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$error=curl_error($h);curl_close($h);
 if($body===false||strlen((string)$body)>1024*1024)return ['status'=>0,'data'=>null,'error'=>'Request failed or response exceeded size limit'];
 return ['status'=>$status,'data'=>json_decode((string)$body,true),'error'=>$error];
}
function publicCommitEvidence(string $email,array $env):array {
 if(($env['GITHUB_PUBLIC_COMMITS_ENABLED']??'0')!=='1')return ['state'=>'not_checked','reason'=>'GitHub public commit adapter disabled','items'=>[]];
 $token=trim((string)($env['GITHUB_TOKEN']??''));
 $query='author-email:"'.$email.'"';
 $headers=['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'];
 if($token!==''){
  if(!str_starts_with($token,'github_pat_')&&!str_starts_with($token,'ghp_'))return ['state'=>'source_unavailable','reason'=>'Unsupported optional GitHub token format','items'=>[]];
  $headers[]='Authorization: Bearer '.$token;
 }
 $res=safeGet('https://api.github.com/search/commits?per_page=10&q='.rawurlencode($query),$headers);
 if($res['status']!==200||!is_array($res['data']))return ['state'=>'source_unavailable','reason'=>'GitHub public commit search returned HTTP '.(int)$res['status'].'; results unavailable, not evidence of absence','items'=>[]];
 $items=[];
 foreach(($res['data']['items']??[]) as $entry){
  if(!is_array($entry))continue;
  $address=strtolower((string)($entry['commit']['author']['email']??''));
  if(!hash_equals($email,$address))continue; // exact-email attribution, not identity verification
  $link=(string)($entry['html_url']??'');
  if(!str_starts_with($link,'https://github.com/'))continue;
  $repo=(string)($entry['repository']['full_name']??'Unknown repository');
  $items[]=[
   'state'=>'observed',
   'type'=>'public_commit_author_email',
   'summary'=>'This exact email string appears in a publicly indexed commit author field.',
   'repository'=>mb_substr($repo,0,180),
   'source_url'=>$link,
   'commit_sha'=>substr((string)($entry['sha']??''),0,40),
   'collected_at'=>gmdate('c'),
   'match_basis'=>'Exact case-insensitive email match in public commit author metadata',
   'caution'=>'Public commit authorship metadata does not establish the identity or current control of an online account.',
  ];
 }
 return ['state'=>$items?'observed':'not_found','reason'=>$items?'Exact-match public commit metadata':'No matching public commit result in this limited API query','items'=>$items];
}
/**
 * Metadata-only source check. HIBP subscriptions cannot be self-provisioned
 * from repository code; enabling is conditional on valid credentials and terms.
 * All breach results are explicitly attributed.
 */
function licensedBreachEvidence(string $email,array $env):array {
 if(($env['HIBP_ENABLED']??'0')!=='1')return ['state'=>'not_checked','reason'=>'Licensed breach provider disabled','items'=>[]];
 $token=$env['HIBP_API_KEY']??'';
 if(!preg_match('/^[a-f0-9]{32}$/i',$token))return ['state'=>'source_unavailable','reason'=>'Licensed provider API key missing or invalid; not checked','items'=>[]];
 $res=safeGet('https://haveibeenpwned.com/api/v3/breachedaccount/'.rawurlencode($email).'?truncateResponse=false',['hibp-api-key: '.$token,'Accept: application/json']);
 if($res['status']===404)return ['state'=>'not_found','reason'=>'No breach returned by the configured provider for this verified self-check','items'=>[]];
 if($res['status']!==200||!is_array($res['data']))return ['state'=>'source_unavailable','reason'=>'Provider unavailable, unauthorised, plan-restricted or rate limited (HTTP '.$res['status'].'); do not interpret as no breach','items'=>[]];
 $items=[];
 foreach(array_slice($res['data'],0,30) as $breach){
  if(!is_array($breach))continue;
  $name=(string)($breach['Name']??'');
  if($name==='')continue;
  $items[]=[
   'state'=>'observed',
   'type'=>'licensed_breach_notification',
   'name'=>mb_substr($name,0,130),
   'breach_date'=>substr((string)($breach['BreachDate']??''),0,10),
   'data_classes'=>array_slice(array_values(array_filter((array)($breach['DataClasses']??[]),'is_string')),0,30),
   'source'=>'Have I Been Pwned — licensed API',
   'collected_at'=>gmdate('c'),
   'caution'=>'A breach listing is exposure context, not proof of account control, culpability or current compromise.',
  ];
 }
 return ['state'=>$items?'observed':'not_found','reason'=>'Authorised breach notification metadata only; no credentials retrieved','items'=>$items];
}
