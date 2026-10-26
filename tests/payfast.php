<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
function cfg(string $name,string $default=''):string{return $default;}
require dirname(__DIR__).'/app/payfast.php';
function assertion(bool $pass,string $message):void{if(!$pass){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
assertion(!pfEnabled(),'No merchant settings means PayFast disabled');
assertion(pfAmount('starter')===0,'Unconfigured subscription price fails closed');
assertion(pfAmount('fake')===0,'Unrecognised plan cannot be billed');
$a=['merchant_id'=>'10000100','amount'=>'100.00','m_payment_id'=>'abc'];
assertion(pfSignature($a,'testing')===pfSignature($a+['signature'=>'bad'],'testing'),'Signature computation excludes signature field');
assertion(pfSignature($a,'testing')!==pfSignature($a,'different'),'Changing passphrase changes signature');
assertion(pfSignature($a,'testing')!==pfSignature(['merchant_id'=>'10000100','amount'=>'101.00','m_payment_id'=>'abc'],'testing'),'Changing payment amount changes signature');
echo "PAYFAST CONTRACT TESTS PASSED\n";
