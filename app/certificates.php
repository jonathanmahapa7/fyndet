<?php
declare(strict_types=1);
/**
 * Optional certificate transparency metadata, scoped to the email DOMAIN.
 * CT evidence identifies certificate issuance, not accounts or email identity.
 * Opt-in to avoid unsolicited traffic to an external third-party service.
 */
function fyndetCertificateEvidence(string $email,array $env):array{
 if(($env['CERTIFICATE_TRANSPARENCY_ENABLED']??'0')!=='1')return fyndetDiscoveryResult('not_checked','Certificate transparency source disabled');
 $domain=substr(strrchr($email,'@')?:'',1);
 if(!$domain||!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return fyndetDiscoveryResult('invalid','Invalid email domain');
 if(!function_exists('curl_init'))return fyndetDiscoveryResult('source_unavailable','PHP cURL is unavailable');
 $url='https://crt.sh/?'.http_build_query(['q'=>$domain,'output'=>'json']);
 $curl=curl_init($url);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'FyndEt/1.0 (verified self-check; domain certificate audit)']);
 $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if(!is_string($body)||strlen($body)>1200000||$status!==200)return fyndetDiscoveryResult('source_unavailable','Certificate transparency source unavailable or response too large');
 $json=json_decode($body,true);if(!is_array($json))return fyndetDiscoveryResult('source_unavailable','Certificate source returned invalid data');
 $seen=[];$items=[];
 foreach($json as $certificate){
  if(!is_array($certificate))continue;
  foreach(explode("\n",(string)($certificate['name_value']??'')) as $name){
   $name=strtolower(trim($name));$exact=$name===$domain;$subdomain=str_ends_with($name,'.'.$domain)&&substr_count($name,'.')<=6;
   if(!$exact&&!$subdomain)continue;
   if(isset($seen[$name]))continue;$seen[$name]=true;
   $items[]=['type'=>'certificate_transparency_domain','name'=>$name,'source'=>'crt.sh certificate transparency public index','issuer'=>mb_substr((string)($certificate['issuer_name']??''),0,180),'not_before'=>substr((string)($certificate['not_before']??''),0,22),'confidence'=>'observed_certificate_name','caution'=>'Certificate records disclose domain infrastructure, not email identity, people, or platform membership.','collected_at'=>gmdate('c')];
   if(count($items)>=45)break 2;
  }
 }
 return fyndetDiscoveryResult($items?'observed':'not_found','Bounded public certificate transparency lookup (domain infrastructure only)',$items);
}
