<?php
declare(strict_types=1);
/**
 * Read-only cPanel readiness report. Never prints secrets or changes application state.
 * Usage: php bin/preflight-cpanel.php
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);$env=[];
if(is_file($root.'/.env'))foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
 $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
 [$key,$val]=explode('=',$line,2);$env[trim($key)]=trim($val," \t\n\r\0\x0B\"'");
}
$errors=0;$warnings=0;
function result(bool $valid,string $label,bool $required=true):void {
 global $errors,$warnings;
 if($valid){echo "[PASS] $label\n";return;}
 if($required){$errors++;echo "[FAIL] $label\n";}
 else {$warnings++;echo "[WARN] $label\n";}
}
echo "FYNDET / CPANEL READINESS — ".gmdate('Y-m-d H:i:s')." UTC\n";
result(PHP_VERSION_ID>=80200,'PHP 8.2+');
foreach(['pdo_mysql','mbstring','openssl','json','curl'] as $extension)result(extension_loaded($extension),"Extension $extension");
result(function_exists('dns_get_record'),'Public DNS resolution function available');
result(is_file($root.'/.env'),'Private environment configuration exists');
result(preg_match('/^[a-f0-9]{64}$/i',$env['APP_KEY']??'')===1,'64-character random application encryption key configured');
result(is_dir($root.'/public')&&is_file($root.'/public/index.php'),'Public application entry point exists');
result(is_file($root.'/public/.htaccess'),'Apache rewrite and security policy exists');
result(is_file($root.'/vendor/autoload.php'),'Composer production dependencies installed',false);
$result=is_dir($root.'/var')&&is_writable($root.'/var');
result($result,'Private var/ directory exists and is writable');
$permission=is_file($root.'/.env')?(fileperms($root.'/.env')&0077):null;
result($permission===0,'Private .env has no group/other permissions',false);
$url=$env['APP_URL']??'';
$isTesting=($env['APP_ENV']??'')==='testing';
result(str_starts_with($url,'https://')||($isTesting&&str_starts_with($url,'http://127.0.0.1:')),'Approved app URL scheme');
result(filter_var($env['MAIL_FROM']??'',FILTER_VALIDATE_EMAIL)!==false,'Verified-domain outbound mail sender configured');
$databaseReady=true;
foreach(['DB_NAME','DB_USER','DB_HOST'] as $key)if(empty($env[$key]))$databaseReady=false;
result($databaseReady,'Database credentials configured');
if($databaseReady){
 try{
  $db=new PDO('mysql:host='.$env['DB_HOST'].';port='.($env['DB_PORT']??3306).';dbname='.$env['DB_NAME'].';charset=utf8mb4',$env['DB_USER'],$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  result(true,'Database connection succeeds');
  foreach(['users','investigations','usage_ledger','billing_events','email_verification_tokens','auth_rate_limits','password_reset_tokens'] as $table){
   $q=$db->query("SHOW TABLES LIKE ".$db->quote($table));result((bool)$q->fetch(),"Required table $table");
  }
  $columns=$db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  foreach(['email_verified_at','session_version'] as $col)result(in_array($col,$columns,true),"User schema column $col");
 }catch(Throwable $e){result(false,'Database available and schema applied');}
}
$secret=$env['STRIPE_SECRET_KEY']??'';$portal=$env['STRIPE_WEBHOOK_SECRET']??'';
result(!str_starts_with($secret,'sk_') || str_starts_with($portal,'whsec_'),'Configured Stripe billing has signed webhooks',false);
$enabled=($env['PAID_BILLING_ENABLED']??'0')==='1';
result(!$enabled || (str_starts_with($secret,'sk_')&&str_starts_with($portal,'whsec_')),'Paid billing enabled only with checkout and webhook credentials',true);
if(($env['HIBP_ENABLED']??'0')==='1')result(!empty($env['HIBP_API_KEY']),'Licensed breach adapter has API credentials');
if(($env['GITHUB_PUBLIC_COMMITS_ENABLED']??'0')==='1')result(!empty($env['GITHUB_TOKEN']),'Public commit adapter has API credentials');
echo "\nSummary: $errors blocking failures, $warnings advisories\n";
exit($errors>0?1:0);
