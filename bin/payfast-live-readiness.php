<?php
declare(strict_types=1);
/** PayFast Phase 2/3 release assessment. Safe read-only; does not contact or charge live PayFast. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);
$dotenv=static function(string $path):array{
 $result=[];
 if(!is_file($path))return $result;
 foreach(file($path,FILE_IGNORE_NEW_LINES)?:[] as $line){
  $line=trim($line);
  if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
  [$key,$value]=explode('=',$line,2);
  $result[trim($key)]=trim(trim($value),chr(34).chr(39));
 }
 return $result;
};
$env=$dotenv($root.'/.env');
$staged=$dotenv($root.'/var/payfast-live.env');
$pass=static function(string $msg,bool $ok):void{echo ($ok?'PASS: ':'ACTION REQUIRED: ').$msg.PHP_EOL;};
$checks=[
 'USD platform pricing'=>($env['PLATFORM_CURRENCY']??'')==='USD'&&($env['PRICE_STARTER_USD']??'')==='15.00'&&($env['PRICE_PRO_USD']??'')==='25.00'&&($env['PRICE_BUSINESS_USD']??'')==='33.00',
 'Sandbox payments only'=>($env['PAYFAST_MODE']??'')==='sandbox'&&($env['PAYFAST_LIVE_APPROVED']??'0')==='0'&&($env['PAID_BILLING_ENABLED']??'0')==='0',
 'Sandbox checkout configured'=>($env['PAYFAST_ENABLED']??'0')==='1',
 'Staged live merchant ID format'=>preg_match('/^[0-9]{8}$/D',$staged['PAYFAST_MERCHANT_ID']??'')===1,
 'Staged live merchant key present'=>strlen($staged['PAYFAST_MERCHANT_KEY']??'')>=8,
 'Staged live merchant passphrase present'=>strlen($staged['PAYFAST_PASSPHRASE']??'')>=8,
 'Live merchant identifier differs from sandbox'=>!empty($staged['PAYFAST_MERCHANT_ID'])&&($staged['PAYFAST_MERCHANT_ID']!==($env['PAYFAST_MERCHANT_ID']??'')),
 'Live approval is not pre-authorised'=>($staged['PAYFAST_LIVE_APPROVED']??'0')==='0',
];
echo "FYNDET PAYFAST — PHASE 2/3 SAFETY AUDIT".PHP_EOL;
foreach($checks as $name=>$ok)$pass($name,(bool)$ok);
echo "Credential values deliberately withheld.".PHP_EOL;
echo "Live merchant validation requires account approval and a separate controlled acceptance test.".PHP_EOL;
echo "No live API call or configuration change was made.".PHP_EOL;
if(!$checks['USD platform pricing']||!$checks['Sandbox payments only'])exit(1);
