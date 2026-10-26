<?php
declare(strict_types=1);
/**
 * FyndEt mount-point adapter. Production target: /fyndet.
 * Before the main controller routes, translate its public URI to an app-local
 * route while preserving subdirectory-safe HTML asset links and form actions.
 * Set FYNDET_BASE_PATH=/fyndet in Apache for this shared-domain installation.
 */
$mount=rtrim((string)(getenv('FYNDET_BASE_PATH')?:''),'/');
if($mount!=='' && (!str_starts_with($mount,'/') || !preg_match('~^/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~',$mount))) {
 http_response_code(503);exit('Invalid application mount point.');
}
define('FYNDET_MOUNT',$mount);
if($mount!==''){
 $request=(string)($_SERVER['REQUEST_URI']??'/');
 $urlPath=(string)(parse_url($request,PHP_URL_PATH)?:'/');
 if($urlPath!==$mount && !str_starts_with($urlPath,$mount.'/')){
  http_response_code(404);exit('Not found');
 }
 $suffix=substr($request,strlen($mount));
 $_SERVER['REQUEST_URI']=($suffix===''?'/':(str_starts_with($suffix,'/')?$suffix:'/'.$suffix));
 // Existing output uses root-relative references. Prefix only literal HTML
 // attribute URLs; do not rewrite external URLs, data, body text or JSON.
 ob_start(static function(string $html)use($mount):string {
  $type=(string)(headers_list()[array_search(true,array_map(static fn($h)=>str_starts_with(strtolower($h),'content-type:'),headers_list()),true)]??'');
  if(str_contains(strtolower($type),'application/json')||str_contains(strtolower($type),'text/plain')||str_contains(strtolower($type),'application/xml'))return $html;
  return str_replace(['href="/','action="/','src="/'],['href="'.$mount.'/','action="'.$mount.'/','src="'.$mount.'/'],$html);
 });
}
