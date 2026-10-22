<?php
declare(strict_types=1);
/**
 * cPanel provider preflight. No user email queries, no secrets printed.
 * Usage: php bin/check-discovery-providers.php
 */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
 $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
}
require_once $root.'/app/providers.php';
$matrix=[
 'DNS'=>'enabled: public DNS source',
 'GitHub'=>(($env['GITHUB_PUBLIC_COMMITS_ENABLED']??'0')==='1'&&!empty($env['GITHUB_TOKEN']))?'configured; live permission test required':'disabled / GitHub token missing',
 'Brave public web'=>(($env['BRAVE_SEARCH_ENABLED']??'0')==='1'&&!empty($env['BRAVE_SEARCH_API_KEY']))?'configured; live permission test required':'disabled / API subscription missing',
 'HIBP breaches'=>(($env['HIBP_ENABLED']??'0')==='1'&&preg_match('/^[a-f0-9]{32}$/i',(string)($env['HIBP_API_KEY']??'')))?'configured; validate with approved test account':'disabled / valid paid API key missing',
 'Certificate transparency'=>(($env['CERTIFICATE_TRANSPARENCY_ENABLED']??'0')==='1')?'enabled; provider availability not guaranteed':'disabled',
];
foreach($matrix as $name=>$status)echo str_pad($name,26).$status."\n";
echo "No provider credentials are disclosed. Configuration presence does not prove licence validity.\n";
echo "HIBP licensing and permissions: https://haveibeenpwned.com/API/v3\n";
