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
assertion(str_contains(pfCheckoutCsp('sandbox'),'form-action https://sandbox.payfast.co.za'),'Sandbox checkout CSP permits sandbox PayFast form');
assertion(!str_contains(pfCheckoutCsp('sandbox'),'www.payfast.co.za'),'Sandbox checkout CSP excludes live gateway');
assertion(str_contains(pfCheckoutCsp('live'),'form-action https://www.payfast.co.za'),'Live checkout CSP permits live gateway');
assertion(!str_contains(pfCheckoutCsp('live'),'sandbox.payfast.co.za'),'Live checkout CSP excludes sandbox gateway');
$sample=['id'=>str_repeat('a',32),'plan'=>'starter','amount_cents'=>25000];
assertion(pfReusablePendingOrder($sample,'starter',25000)===str_repeat('a',32),'Matching pending checkout retains original reference');
foreach ([['pro',25000],['starter',41500]] as [$plan,$amount]) {
 $rejected=false;
 try {pfReusablePendingOrder($sample,$plan,$amount);}catch(DomainException $e){$rejected=true;}
 assertion($rejected,'Mismatched pending checkout cannot be reused');
}
$rejected=false;
try{pfReusablePendingOrder(['id'=>'bad','plan'=>'starter','amount_cents'=>25000],'starter',25000);}catch(DomainException $e){$rejected=true;}
assertion($rejected,'Malformed pending checkout reference rejected');
assertion(pfZarCentsForUsd(1500,18.5)===27750,'USD 15 quotes ZAR 277.50');
assertion(pfZarCentsForUsd(2500,18.5)===46250,'USD 25 quotes ZAR 462.50');
assertion(pfZarCentsForUsd(3300,18.5)===61050,'USD 33 quotes ZAR 610.50');
assertion(pfZarCentsForUsd(1500,0)===0,'Invalid FX rate fails closed');
assertion(pfPendingAction(null,'starter',24858,'sandbox')==='create','No pending order creates a quote');
assertion(pfPendingAction(['plan'=>'starter','amount_cents'=>24858],'starter',24858,'sandbox')==='reuse','Identical sandbox quote is reused');
assertion(pfPendingAction(['plan'=>'starter','amount_cents'=>25000],'starter',24858,'sandbox')==='supersede','Obsolete sandbox ZAR quote is superseded');
assertion(pfPendingAction(['plan'=>'pro','amount_cents'=>41430],'starter',24858,'sandbox')==='supersede','Pending sandbox plan change replaces quote');
$liveRejected=false;
try{pfPendingAction(['plan'=>'starter','amount_cents'=>25000],'starter',24858,'live');}catch(DomainException $e){$liveRejected=true;}
assertion($liveRejected,'Live pending checkout may not be replaced automatically');
$hostedFields=['merchant_id'=>'10004002','merchant_key'=>'q1cd2rdny4a53','return_url'=>'https://example.com/return','amount'=>'248.58',...pfCheckoutSubscriptionFields(24858)];
assertion(array_keys(pfCheckoutSubscriptionFields(24858))===['subscription_type','recurring_amount','frequency','cycles'],'Recurring fields follow PayFast hosted checkout signing order');
assertion(pfSignature($hostedFields,'payfast')==='4efb7e372524c802836430a474d0ca0b','PayFast signature matches independent Python MD5 and URL encoding fixture');
$wrongOrder=$hostedFields;unset($wrongOrder['recurring_amount'],$wrongOrder['frequency'],$wrongOrder['cycles']);
$wrongOrder['frequency']='3';$wrongOrder['cycles']='0';$wrongOrder['recurring_amount']='248.58';
assertion(pfSignature($wrongOrder,'payfast')!==pfSignature($hostedFields,'payfast'),'Recurring field order materially changes gateway signature');
$order=['id'=>str_repeat('b',32),'mode'=>'sandbox','status'=>'complete'];
$sub=['order_id'=>str_repeat('b',32),'mode'=>'sandbox','status'=>'active','token'=>'token-one'];
assertion(pfRenewalPolicy($order,$sub,'COMPLETE','second-payment','token-one')==='activate','Matching successful renewal remains eligible');
assertion(pfRenewalPolicy($order,$sub,'FAILED','failed-payment','token-one')==='record_only','Failed renewal cannot grant paid access');
assertion(pfRenewalPolicy($order,[...$sub,'status'=>'cancelled'],'COMPLETE','late-payment','token-one')==='record_only','Late payment cannot reactivate cancelled subscription');
assertion(pfRenewalPolicy($order,$sub,'COMPLETE','second-payment','different-token')==='reject','Recurring token mismatch rejected');
assertion(pfRenewalPolicy([...$order,'status'=>'superseded'],$sub,'COMPLETE','second-payment','token-one')==='reject','Superseded order cannot activate');
assertion(pfRenewalPolicy($order,[...$sub,'order_id'=>str_repeat('c',32)],'COMPLETE','second-payment','token-one')==='reject','Wrong subscription order rejected');
// This file also contains route handlers; keep $path empty when loading it.
$path='';
require_once dirname(__DIR__).'/app/payfast_manage.php';
$cancelled=['status'=>'success','data'=>['response'=>['status_text'=>'CANCELLED']]];
assertion(pfApiSubscriptionCancelledResponse(200,$cancelled),'Confirmed provider cancellation accepted');
assertion(!pfApiSubscriptionCancelledResponse(200,['status'=>'success','data'=>['response'=>['status_text'=>'ACTIVE']]]),'Active provider status rejected');
assertion(!pfApiSubscriptionCancelledResponse(401,$cancelled),'Unauthenticated provider status rejected');
assertion(!pfApiSubscriptionCancelledResponse(200,['status'=>'success','data'=>['response'=>true]]),'Bare success boolean cannot substitute for cancelled state');
assertion(preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/',gmdate('Y-m-d\\TH:i:s+00:00'))===1,'ISO-8601 subscription API timestamp format');
echo "PAYFAST CONTRACT TESTS PASSED\n";
