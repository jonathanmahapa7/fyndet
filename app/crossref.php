<?php
declare(strict_types=1);
/**
 * Public scholarly metadata from Crossref. Verified self-check only.
 * Search returns candidate documents; include only EXACT email matches in
 * the bounded returned metadata to avoid invented author associations.
 */
function fyndetCrossrefEvidence(string $email):array{
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['state'=>'invalid','reason'=>'Invalid email','items'=>[]];
 if(!function_exists('curl_init'))return ['state'=>'source_unavailable','reason'=>'cURL unavailable','items'=>[]];
 $url='https://api.crossref.org/works?'.http_build_query(['query.bibliographic'=>'"'.$email.'"','rows'=>8,'select'=>'DOI,title,author,published,URL,abstract,publisher']);
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'FyndEt/1.0 (https://jonathanmahapa.co.za/fyndet; source evidence checking)']);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($status!==200||!is_string($raw)||strlen($raw)>1000000)return ['state'=>'source_unavailable','reason'=>'Crossref metadata unavailable (HTTP '.$status.')','items'=>[]];
 $json=json_decode($raw,true);if(!is_array($json))return ['state'=>'source_unavailable','reason'=>'Invalid metadata response','items'=>[]];
 $items=[];
 foreach(($json['message']['items']??[]) as $record){
  if(!is_array($record))continue;
  $doi=(string)($record['DOI']??'');
  if(!preg_match('~^10\.[0-9]{4,9}/[^\s]+$~',$doi))continue;
  $plain=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
  if(!is_string($plain)||stripos($plain,$email)===false)continue;
  $items[]=['type'=>'public_scholarly_email_mention','title'=>mb_substr((string)($record['title'][0]??'Scholarly publication'),0,160),'summary'=>'The exact email string appears in publicly indexed Crossref record metadata. This does not establish author identity.','source'=>'Crossref public scholarly metadata','source_url'=>'https://doi.org/'.rawurlencode($doi),'collected_at'=>gmdate('c'),'match_basis'=>'Exact case-insensitive email string in returned DOI metadata','confidence'=>'exact_metadata_mention','caution'=>'Publisher-deposited metadata may be incomplete or outdated; verify the linked DOI.'];
 }
 return ['state'=>$items?'observed':'not_found','reason'=>'Eight or fewer publicly available Crossref metadata candidates examined for the exact email string; not a comprehensive publication search','items'=>$items];
}
