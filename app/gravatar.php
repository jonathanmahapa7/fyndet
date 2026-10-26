<?php
declare(strict_types=1);
/** Bounded exact-email Gravatar public profile reference. Verified self-check only. */
function fyndetGravatarEvidence(string $email):array{
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['state'=>'invalid','reason'=>'Invalid email','items'=>[]];
 if(!function_exists('curl_init'))return ['state'=>'source_unavailable','reason'=>'cURL unavailable','items'=>[]];
 $hash=hash('sha256',strtolower(trim($email)));
 $url='https://api.gravatar.com/v3/profiles/'.$hash;
 $h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'FyndEt/1.0 public self-check profile evidence']);
 $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if($status===404)return ['state'=>'not_found','reason'=>'No public Gravatar profile returned by this query','items'=>[]];
 if($status!==200||!is_string($body)||strlen($body)>200000)return ['state'=>'source_unavailable','reason'=>'Public profile API unavailable (HTTP '.$status.')','items'=>[]];
 $data=json_decode($body,true);
 if(!is_array($data))return ['state'=>'source_unavailable','reason'=>'Invalid profile response','items'=>[]];
 $url='https://gravatar.com/'.$hash;
 $name=mb_substr((string)($data['display_name']??$data['name']??'Public Gravatar profile'),0,120);
 $summary='A public Gravatar profile was returned for the SHA-256 hash of the verified email. This is a platform association, not proof of current identity or account control.';
 return ['state'=>'observed','reason'=>'Public Gravatar profile endpoint returned an exact hashed-email association','items'=>[['type'=>'public_profile_reference','title'=>$name,'summary'=>$summary,'source'=>'Gravatar public API','source_url'=>$url,'collected_at'=>gmdate('c'),'match_basis'=>'SHA-256 of case-normalised verified email used as exact profile lookup key','confidence'=>'observed_public_profile','caution'=>'Profile fields are self-provided; do not infer identity or account control.']]];
}
