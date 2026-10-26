<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$env=['PRICE_STARTER_USD'=>'15.00','PRICE_PRO_USD'=>'25.00','PRICE_BUSINESS_USD'=>'33.00','FX_USD_ZAR_ENABLED'=>'0'];
function cfg(string $key,string $default=''):string {global $env;return $env[$key]??$default;}
require dirname(__DIR__).'/app/pricing_currency.php';
function check(bool $ok,string $msg):void {if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
check(fyndetUsdCents('starter')===1500,'Starter fixed USD 15');
check(fyndetUsdCents('pro')===2500,'Pro fixed USD 25');
check(fyndetUsdCents('business')===3300,'Business fixed USD 33');
check(fyndetUsdCents('invalid')===0,'Unknown plan rejected');
check(fyndetFxRate()===null,'Disabled reference API never queried');
check(fyndetIndicativeZar('starter',['rate'=>18.5])==='277.50','USD conversion is indicative');
check(fyndetIndicativeZar('pro',null)===null,'Unavailable rate does not invent conversion');
$env['PRICE_STARTER_USD']='15.123';
$reject=false;try{fyndetUsdCents('starter');}catch(DomainException $e){$reject=true;}
check($reject,'Malformed USD price fails closed');
echo "USD PRICING CONTRACT TESTS PASSED\n";
