<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
function cfg(string $name,string $default=''):string{return $default;}
$path='/__payfast_contract_test__';
require dirname(__DIR__).'/app/payfast.php';
function assertion(bool $pass,string $message):void{if(!$pass){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
assertion(!pfEnabled(),'No merchant settings means PayFast disabled');
assertion(pfAmount('starter')===0,'Unconfigured subscription price fails closed');
assertion(pfAmount('fake')===0,'Unrecognised plan cannot be billed');
$a=['merchant_id'=>'10000100','amount'=>'100.00','m_payment_id'=>'abc'];
assertion(pfSignature($a,'testing')===pfSignature($a+['signature'=>'bad'],'testing'),'Signature computation excludes signature field');
assertion(pfSignature($a,'testing')!==pfSignature($a,'different'),'Changing passphrase changes signature');
assertion(pfSignature($a,'testing')!==pfSignature(['merchant_id'=>'10000100','amount'=>'101.00','m_payment_id'=>'abc'],'testing'),'Changing payment amount changes signature');
assertion(pfParameterString($a) === 'merchant_id=10000100&amount=100.00&m_payment_id=abc', 'Canonical PayFast server-validation payload preserves ordering');
assertion(!str_contains(pfParameterString($a+['signature'=>'fake']),'signature='),'Server confirmation excludes signature field');
assertion(pfItnParameterString(['m_payment_id'=>'123','name_first'=>'','signature'=>'abc'])==='m_payment_id=123&name_first=', 'PayFast ITN canonicalisation preserves empty fields before signature');
foreach (['250.00'=>25000,'415.00'=>41500,'550.00'=>55000,'0.00'=>0] as $gross=>$cents)assertion(pfGrossCents($gross)===$cents,'Exact amount parsing '.$gross);
foreach (['250','250.0','250.001','2.5e2','-250.00','250.00x','0250.00','999999999.00'] as $invalid)assertion(pfGrossCents($invalid)===null,'Malformed amount rejected: '.$invalid);
assertion(pfGrossCents(['250.00'])===null,'Array amount rejected');
echo "PAYFAST CONTRACT TESTS PASSED\n";
