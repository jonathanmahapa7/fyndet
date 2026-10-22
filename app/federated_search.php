<?php
declare(strict_types=1);
/**
 * Federated public-source queries. All lookups require registered-email
 * ownership verification in the calling worker.
 *
 * Indexed snippets do not establish account ownership.
 * Never download arbitrary matched pages, credentials or scraped data.
 */
function fyndetFederatedSearch(string $email,array $env,string $category):array{
 $config=[
  'public_documents'=>['suffix'=>'filetype:pdf','meaning'=>'Search-indexed PDF/document email mention'],
  'professional_references'=>['suffix'=>'(business OR company OR professional OR speaker)','meaning'=>'Search-indexed professional contextual mention'],
  'community_references'=>['suffix'=>'(forum OR discussion OR event OR conference)','meaning'=>'Search-indexed community contextual mention'],
 ];
 if(!isset($config[$category]))return fyndetDiscoveryResult('invalid','Unsupported public source category');
 if(($env['BRAVE_SEARCH_ENABLED']??'0')!=='1')return fyndetDiscoveryResult('not_checked','Indexed web provider not enabled');
 $token=(string)($env['BRAVE_SEARCH_API_KEY']??'');
 if($token==='')return fyndetDiscoveryResult('source_unavailable','Brave Search API key unavailable');
 if(!function_exists('curl_init'))return fyndetDiscoveryResult('source_unavailable','PHP cURL missing');
 $q='"'.$email.'" '.$config[$category]['suffix'];
 $url='https://api.search.brave.com/res/v1/web/search?'.http_build_query(['q'=>$q,'count'=>10]);
 $h=curl_init($url);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>14,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json','X-Subscription-Token: '.$token],CURLOPT_USERAGENT=>'FyndEt/1.0 (registered email owner public footprint investigation)']);
 $response=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if(!is_string($response)||strlen($response)>800000||$status!==200)return fyndetDiscoveryResult('source_unavailable','Indexed search unavailable or rate limited (HTTP '.$status.')');
 $json=json_decode($response,true);if(!is_array($json))return fyndetDiscoveryResult('source_unavailable','Invalid indexed-search response');
 $found=[];$seen=[];
 foreach(($json['web']['results']??[]) as $row){
  if(!is_array($row))continue;
  $link=(string)($row['url']??'');
  if(!str_starts_with($link,'https://')||!filter_var($link,FILTER_VALIDATE_URL)||isset($seen[$link]))continue;
  $title=trim(strip_tags((string)($row['title']??'')));
  $snippet=trim(strip_tags((string)($row['description']??'')));
  if(!str_contains(strtolower($title.' '.$snippet),$email))continue;
  $seen[$link]=true;
  $found[]=['type'=>'indexed_'.$category,'title'=>mb_substr($title,0,160),'summary'=>mb_substr($snippet,0,360),'source_url'=>$link,'source'=>'Brave Search API indexed public result','confidence'=>'exact_email_string_in_indexed_snippet','match_basis'=>'Exact email mention in indexed title or snippet','collected_at'=>gmdate('c'),'caution'=>'Indexed references may be stale, incorrect, or unrelated to current account control.'];
  if(count($found)>=10)break;
 }
 return fyndetDiscoveryResult($found?'observed':'not_found',$config[$category]['meaning'].'; limited to provider indexed matches',$found);
}
