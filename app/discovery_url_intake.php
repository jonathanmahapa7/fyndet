<?php
declare(strict_types=1);
require_once __DIR__.'/discovery_verifier.php';
/** Query string is retained because different query values may identify different documents. */
function fyndetR0Canonical(string $url):?string {
 if(!fyndetVUrl($url))return null;
 $p=parse_url($url);$scheme=strtolower($p['scheme']);$host=strtolower($p['host']);
 $port=isset($p['port'])?':'.$p['port']:'';
 $path=$p['path']??'/';if($path==='')$path='/';
 return $scheme.'://'.$host.$port.$path.(isset($p['query'])?'?'.$p['query']:'');
}
function fyndetR0Match(string $email,string $html):?array {
 $text=html_entity_decode(strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\\1>~is','',$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
 $parts=explode('@',strtolower($email),2);
 if(count($parts)!==2)return null;
 $local=preg_quote($parts[0],'~');$domain=preg_quote($parts[1],'~');
 $expr=['~(?<![a-z0-9._%+\\-])'.preg_quote($email,'~').'(?![a-z0-9.\\-])~i',
 '~(?<![a-z0-9._%+\\-])'.$local.'\\s*(?:\\[at\\]|\\(at\\)| at )\\s*'.$domain.'(?![a-z0-9.\\-])~i'];
 foreach($expr as $pattern)if(preg_match($pattern,$text,$m,PREG_OFFSET_CAPTURE)===1){
  $offset=$m[0][1];return ['snippet'=>substr($text,max(0,$offset-100),340),
  'content_hash'=>hash('sha256',$text),'basis'=>str_contains(strtolower($m[0][0]),'@')?'exact':'obfuscated'];
 }return null;
}
/** Tests inject synthetic bounded fetch responses; production uses fyndetVRobots/fyndetVFetch. */
function fyndetR0Verify(string $email,string $url,?callable $fetch=null):array {
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['state'=>'invalid_email'];
 $canonical=fyndetR0Canonical($url);if(!$canonical)return ['state'=>'blocked','reason'=>'invalid_or_unsafe_url'];
 $p=parse_url($canonical);$query=rawurldecode($p['query']??'');
 if($query!=='' && stripos($query,$email)!==false)return ['state'=>'user_reported','reason'=>'query_echo_url'];
 if($fetch){$r=$fetch($canonical);}else{
  $robots=fyndetVRobots($canonical);if(empty($robots['allowed']))return ['state'=>'blocked','reason'=>$robots['reason']??'robots_denied'];
  $r=fyndetVFetch($canonical);
 }
 if(($r['state']??'')!=='fetched')return ['state'=>'user_reported','reason'=>$r['reason']??'not_fetchable','http_status'=>$r['http_status']??null];
 $match=fyndetR0Match($email,(string)($r['body']??''));
 if(!$match)return ['state'=>'user_reported','reason'=>'email_not_in_content','http_status'=>$r['http_status']??200];
 return ['state'=>'content_verified','reason'=>'public_email_mention','http_status'=>200,'canonical_url'=>$canonical]+$match;
}
