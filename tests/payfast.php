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
$nonce=base64_encode(str_repeat('X',18));
assertion(str_contains(pfCheckoutRedirectCsp('live',$nonce),"script-src 'nonce-".$nonce."'"),'Live PayFast automatic POST allows only its one-time script');
assertion(str_contains(pfCheckoutRedirectCsp('live',$nonce),'form-action https://www.payfast.co.za'),'Automatic production handoff posts to live gateway');
assertion(!str_contains(pfCheckoutRedirectCsp('live',$nonce),'sandbox.payfast.co.za'),'Automatic production handoff never permits sandbox gateway');
$invalidNonce=false;try{pfCheckoutRedirectCsp('live','bad');}catch(DomainException $e){$invalidNonce=true;}
assertion($invalidNonce,'Malformed automatic-checkout script nonce rejected');

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
require_once dirname(__DIR__).'/app/payfast_plan_changes.php';
assertion(pfPlanChangeDirection('starter','pro')==='upgrade','Starter to Pro is an upgrade');
assertion(pfPlanChangeDirection('business','starter')==='downgrade','Business to Starter is a downgrade');
$invalid=false;try{pfPlanChangeDirection('starter','starter');}catch(DomainException $e){$invalid=true;}
assertion($invalid,'Same-plan request rejected');
$invalid=false;try{pfPlanChangeDirection('starter','other');}catch(DomainException $e){$invalid=true;}
assertion($invalid,'Unknown target plan rejected');
assertion(pfItnAmountDecision(24858,24858,null)==='original','Original authorised amount accepted');
assertion(pfItnAmountDecision(24858,41430,null)==='reject','Unauthorised renewal amount rejected');
assertion(pfItnAmountDecision(24858,41430,['status'=>'awaiting_review','quoted_zar_cents'=>41430])==='reject','Unconfirmed plan-change quote cannot authorise renewal');
assertion(pfItnAmountDecision(24858,41430,['status'=>'provider_confirmed','quoted_zar_cents'=>41430])==='changed','Provider-confirmed amount authorised');
assertion(pfItnAmountDecision(24858,41430,['status'=>'applied','quoted_zar_cents'=>41430])==='changed','Applied amount accepted on subsequent renewals');
assertion(pfItnAmountDecision(24858,42000,['status'=>'applied','quoted_zar_cents'=>41430])==='reject','Unexpected recurring amount rejected');
assertion(pfPlanChangeQuoteValid(2500,41430,2500,41430),'Exact customer quote can be confirmed');
assertion(!pfPlanChangeQuoteValid(2500,41430,2500,41500),'Stale currency quote must be refreshed');
assertion(!pfPlanChangeQuoteValid(2500,41430,3300,41430),'Changed plan price must be reauthorised');
assertion(pfEffectivePlanForRenewal('starter','changed',['status'=>'provider_confirmed','to_plan'=>'pro'])==='pro','Verified changed renewal activates requested plan');
assertion(pfEffectivePlanForRenewal('starter','changed',['status'=>'applied','to_plan'=>'pro'])==='pro','Later renewals retain upgraded plan');
assertion(pfEffectivePlanForRenewal('pro','original',['status'=>'provider_confirmed','to_plan'=>'starter'])==='pro','Old amount cannot activate downgrade');
assertion(pfEffectivePlanForRenewal('starter','changed',['status'=>'awaiting_review','to_plan'=>'pro'])==='starter','Unconfirmed plan cannot activate');
$headers=['merchant-id'=>'10004002','version'=>'v1','timestamp'=>'2026-10-26T20:00:00+00:00'];
assertion(pfApiSubscriptionUpdateSignature($headers,['amount'=>'41430'])!==pfApiSubscriptionUpdateSignature($headers,['amount'=>'24858']),'PayFast subscription update signature binds the exact future amount');
assertion(pfApiSubscriptionUpdateSignature($headers,['amount'=>'41430'])===pfApiSignature([...$headers,'amount'=>'41430']),'PayFast API signing is canonical across body ordering');
assertion(pfItnAmountDecision(24858,24858,['status'=>'applied','quoted_zar_cents'=>41430])==='reject','Stale original amount rejected after activated upgrade');
$confirmedPayload=['status'=>'success','data'=>['response'=>['status_text'=>'ACTIVE','amount'=>41430]]];
assertion(pfApiConfirmedAmount(200,$confirmedPayload,41430),'Exact active provider recurring amount confirmed');
assertion(!pfApiConfirmedAmount(401,$confirmedPayload,41430),'Unauthorised fetch cannot confirm recurring amount');
assertion(!pfApiConfirmedAmount(200,$confirmedPayload,42000),'Wrong provider amount cannot confirm update');
assertion(!pfApiConfirmedAmount(200,['status'=>'success','data'=>['response'=>['status_text'=>'CANCELLED','amount'=>41430]]],41430),'Cancelled provider subscription cannot confirm update');
assertion(!pfApiConfirmedAmount(200,['status'=>'success','data'=>['response'=>['status_text'=>'ACTIVE','amount'=>'41430.99']]],41430),'Fractional provider amount rejected');
assertion(pfReceiptPlan('pro')==='pro','Pro renewal payment retains its own receipt plan');
assertion(pfReceiptPlan('starter')==='starter','Pre-upgrade payment retains its original receipt plan');
$invalidReceipt=false;try{pfReceiptPlan('enterprise');}catch(DomainException $e){$invalidReceipt=true;}
assertion($invalidReceipt,'Unrecognised receipt plan rejected');
$firstApplied=['status'=>'applied','quoted_zar_cents'=>41430,'to_plan'=>'pro'];
$secondConfirmed=['status'=>'provider_confirmed','quoted_zar_cents'=>24858,'to_plan'=>'starter'];
assertion(pfRenewalAmountResolution(24858,41430,null,$firstApplied)==='existing_plan','Prior upgraded ZAR rate survives later renewals');
assertion(pfRenewalPlanResolution('starter','existing_plan',null,$firstApplied)==='pro','Prior upgraded plan survives later renewals');
assertion(pfRenewalAmountResolution(24858,41430,$secondConfirmed,$firstApplied)==='existing_plan','Old current rate remains valid before later downgrade payment');
assertion(pfRenewalAmountResolution(24858,24858,$secondConfirmed,$firstApplied)==='new_plan','Downgrade back to initial checkout amount is recognised');
assertion(pfRenewalPlanResolution('starter','new_plan',$secondConfirmed,$firstApplied)==='starter','Downgrade activates only on correct later payment');
assertion(pfRenewalAmountResolution(24858,24858,null,$firstApplied)==='reject','Stale original rate rejected after earlier upgrade');
assertion(pfRenewalAmountResolution(24858,42000,null,$firstApplied)==='reject','Unexpected renewed amount rejected after upgrade');
assertion(pfRenewalAmountResolution(24858,24858,null,null)==='existing_plan','Initial checkout rate remains accepted without changes');
$checkoutSource=file_get_contents(dirname(__DIR__).'/app/payfast.php');
assertion(is_string($checkoutSource)&&str_contains($checkoutSource,"\$GLOBALS['csp_nonce']"),'Hosted checkout shares nonce with the application security policy');
assertion(is_string($checkoutSource)&&str_contains($checkoutSource,"header_remove('Content-Security-Policy')"),'Hosted checkout replaces the restrictive application form policy');
assertion(is_string($checkoutSource)&&str_contains($checkoutSource,'HTMLFormElement.prototype.submit.call(f)'),'Hosted checkout uses native automatic POST');
$controller=file_get_contents(dirname(__DIR__).'/public/index.php');
assertion(is_string($controller)&&str_contains($controller,'fyndet-payfast-direct'),'Billing Subscribe uses direct provider handoff');
assertion(str_contains($controller,"'X-FyndEt-Checkout':'direct'"),'Billing checkout fetch uses same-origin signed-order POST');
assertion(str_contains($controller,'HTMLFormElement.prototype.submit.call(gatewayForm)'),'Billing checkout submits signed form directly to provider');
assertion(str_contains($controller,'$billingCheckoutRoute'),'Checkout form security policy scoped to billing route');
$payfastSource=file_get_contents(dirname(__DIR__).'/app/payfast.php');
assertion(is_string($payfastSource)&&str_contains($payfastSource,"HTTP_X_FYNDET_CHECKOUT"),'PayFast creates signed browser checkout fields on demand');
echo "PAYFAST CONTRACT TESTS PASSED\n";
