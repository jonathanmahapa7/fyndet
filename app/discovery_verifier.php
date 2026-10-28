<?php
declare(strict_types=1);
/** Bounded public HTTP verification; never follows redirects or fetches local/private IPs. */
function fyndetVUrl(string $url): ?array {
 $p=parse_url($url);
 if(!is_array($p) || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && !in_array((int)$p['port'],[80,443],true)) || (isset($p['port']) && (int)$p['port']!==(strtolower($p['scheme'])==='https'?443:80)) || strlen($url)>1800) return null;
 $host=strtolower($p['host']);
 if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || filter_var($host,FILTER_VALIDATE_IP))return null;
 return [$host,(string)($p['path']??'/')];
}
function fyndetVResolve(string $host):?string {
 $a=@dns_get_record($host,DNS_A|DNS_AAAA);
 if(!is_array($a)||!$a)return null;
 $ips=[];
 foreach($a as $r){$ip=$r['ip']??$r['ipv6']??null; if(!$ip || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null; $ips[]=$ip;}
 foreach($ips as $ip)if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return $ip;
 return null; // fail closed on IPv6-only destinations for this release
}
function fyndetVFetch(string $url,int $cap=350000):array {
 $p=fyndetVUrl($url);if(!$p)return ['state'=>'blocked','reason'=>'invalid_or_non_https_url'];
 [$host]=$p;$scheme=strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:'https'));$port=$scheme==='https'?443:80;$ip=fyndetVResolve($host);if(!$ip)return ['state'=>'blocked','reason'=>'non_public_destination'];
 $bytes='';$h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>9,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>["$host:$port:$ip"],CURLOPT_USERAGENT=>'FyndEtEvidence/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html, text/plain','Accept-Encoding: identity'],CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$bytes,$cap):int{if(strlen($bytes)+strlen($part)>$cap)return 0;$bytes.=$part;return strlen($part);}]);
 $ok=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$type=strtolower((string)curl_getinfo($h,CURLINFO_CONTENT_TYPE));$error=(string)curl_error($h);curl_close($h);
 if($ok===false)return ['state'=>'not_fetchable','reason'=>$error?'transport_or_size_limit':'transport_error','http_status'=>$code];
 if($code!==200)return ['state'=>'not_fetchable','reason'=>'http_'.$code,'http_status'=>$code];
 if(!str_starts_with($type,'text/html')&&!str_starts_with($type,'text/plain'))return ['state'=>'unsupported_content','reason'=>'content_type','http_status'=>$code];
 return ['state'=>'fetched','body'=>$bytes,'http_status'=>$code];
}
function fyndetVRobots(string $url):array {
 $p=fyndetVUrl($url);if(!$p)return ['allowed'=>false,'reason'=>'invalid_url'];
 [$host,$path]=$p;$r=fyndetVFetch(strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:'https')).'://'.$host.'/robots.txt',90000);
 if(($r['http_status']??0)===404 || ($r['http_status']??0)===410)return ['allowed'=>true,'reason'=>'robots_absent'];
 if(($r['state']??'')!=='fetched')return ['allowed'=>false,'reason'=>'robots_unavailable'];
 $groups=[];$agents=[];$rules=[];$seenRule=false;
 foreach(preg_split('/\r?\n/',(string)$r['body']) as $line){$line=trim(explode('#',$line,2)[0]);if($line==='')continue;
  if(preg_match('/^User-agent\s*:\s*(.*)$/i',$line,$m)){if($seenRule){$groups[]=['agents'=>$agents,'rules'=>$rules];$agents=[];$rules=[];$seenRule=false;}$agents[]=strtolower(trim($m[1]));}
  elseif(preg_match('/^(Allow|Disallow)\s*:\s*(.*)$/i',$line,$m)){$seenRule=true;$rules[]=[strtolower($m[1]),trim($m[2])];}
 }
 if($agents||$rules)$groups[]=['agents'=>$agents,'rules'=>$rules];
 $matches=[];foreach($groups as $g){$a=$g['agents'];if(in_array('fyndetevidence',$a,true)||in_array('fyndetevidence/1.0',$a,true)||in_array('*',$a,true))foreach($g['rules'] as $rule)$matches[]=$rule;}
 $best=-1;$allowed=true;
 foreach($matches as [$kind,$rule]){if($rule==='')continue;$regex='~^'.str_replace('\\*','.*',preg_quote(rtrim($rule,'$'),'~')).(str_ends_with($rule,'$')?'$':'').'~';if(@preg_match($regex,$path)===1 && strlen($rule)>$best){$best=strlen($rule);$allowed=$kind==='allow';}}
 return ['allowed'=>$allowed,'reason'=>$allowed?'robots_allowed':'robots_disallowed'];
}
function fyndetVCheck(string $email,string $url):array {
 $robots=fyndetVRobots($url);if(!$robots['allowed'])return ['tier'=>'unverified_lead','reason'=>$robots['reason']];
 $r=fyndetVFetch($url);if(($r['state']??'')!=='fetched')return ['tier'=>'unverified_lead','reason'=>$r['reason']??'fetch_error','http_status'=>$r['http_status']??null];
 $body=(string)$r['body'];$text=html_entity_decode(strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\\1>~is','',$body)),ENT_QUOTES|ENT_HTML5,'UTF-8');
 $exact=preg_quote($email,'~');$parts=explode('@',$email,2);$local=preg_quote($parts[0],'~');$domain=preg_quote($parts[1],'~');
 $patterns=['~(?<![a-z0-9._%+\-])'.$exact.'(?![a-z0-9.\-])~i','~'. $local.'\s*(?:\[at\]|\(at\)| at )\s*'.$domain.'~i'];
 $offset=null;foreach($patterns as $p)if(preg_match($p,$text,$m,PREG_OFFSET_CAPTURE)){$offset=$m[0][1];break;}
 if($offset===null)return ['tier'=>'unverified_lead','reason'=>'email_not_in_content','http_status'=>200];
 return ['tier'=>'content_verified','reason'=>'matched_email_in_fetched_content','http_status'=>200,'snippet'=>mb_substr(substr($text,max(0,$offset-100),320),0,320),'content_hash'=>hash('sha256',$body)];
}
