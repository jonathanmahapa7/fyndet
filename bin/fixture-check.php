<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$fixture=$root.'/var/fixture_urls.txt';
if(!is_file($fixture)){echo "NOTE: var/fixture_urls.txt missing; fixture check skipped.\n";exit(0);}
require_once $root.'/app/discovery_url_intake.php';
$email=strtolower(trim((string)(getenv('FYNDET_R0_ADMIN_EMAIL')?:'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,'FYNDET_R0_ADMIN_EMAIL missing'.PHP_EOL);exit(2);}
foreach(array_slice(file($fixture,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[],0,20) as $raw){
 $url=trim($raw);if(!$url||str_starts_with($url,'#'))continue;
 $result=fyndetR0Verify($email,$url);
 echo json_encode(['url'=>$url,'disposition'=>$result['state']??'unknown','reason'=>$result['reason']??null],JSON_UNESCAPED_SLASHES)."\n";
}
