<?php
declare(strict_types=1);
/** Customer-authorised plan-change requests; no gateway amount or entitlement mutation. */
function pfPlanChangeDirection(string $from,string $to):string{
 $levels=['starter'=>1,'pro'=>2,'business'=>3];
 if(!isset($levels[$from],$levels[$to])||$from===$to)throw new DomainException('Choose a different valid subscription.');
 return $levels[$to]>$levels[$from]?'upgrade':'downgrade';
}
if($path==='/payfast/plan-change'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 if(!pfEnabled()||empty($u['email_verified_at']))throw new DomainException('A verified account and configured PayFast subscription are required.');
 $target=(string)($_POST['plan']??'');
 if(!in_array($target,['starter','pro','business'],true))throw new DomainException('Invalid plan.');
 $db=db();$db->beginTransaction();
 try{
  $q=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$u['id']]);
  if(!$q->fetchColumn())throw new DomainException('Account not found.');
  $q=$db->prepare('SELECT order_id,plan,status,mode,token FROM payfast_subscriptions WHERE user_id=? FOR UPDATE');$q->execute([$u['id']]);$sub=$q->fetch();
  if(!$sub||$sub['status']!=='active'||$sub['mode']!==pfMode()||empty($sub['token']))throw new DomainException('You need an active PayFast subscription to request a change.');
  $from=(string)$sub['plan'];$direction=pfPlanChangeDirection($from,$target);
  $q=$db->prepare("SELECT id FROM payfast_plan_changes WHERE user_id=? AND status IN ('awaiting_review','provider_confirmed') LIMIT 1 FOR UPDATE");$q->execute([$u['id']]);
  if($q->fetchColumn())throw new DomainException('An existing plan-change request must be resolved first.');
  $usd=fyndetUsdCents($target);$zar=pfAmount($target);
  if($usd<100||$zar<500)throw new DomainException('A valid currency quote is currently unavailable. Please try later.');
  $q=$db->prepare("INSERT INTO payfast_plan_changes(user_id,order_id,from_plan,to_plan,quoted_usd_cents,quoted_zar_cents,status) VALUES(?,?,?,?,?,?,'awaiting_review')");
  $q->execute([$u['id'],$sub['order_id'],$from,$target,$usd,$zar]);
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 auditEvent((int)$u['id'],'billing.payfast.plan_change_requested',$direction.':'.$target);
 flash('Your '. $direction .' request is recorded. No payment amount or plan benefits have changed. We will confirm terms before any change takes effect.');
 go('app/billing');
}
if($path==='/payfast/plan-change/withdraw'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 $db=db();$db->beginTransaction();
 try{
  $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$u['id']]);
  if(!$lock->fetchColumn())throw new DomainException('Account not found.');
  $q=$db->prepare("UPDATE payfast_plan_changes SET status='withdrawn' WHERE user_id=? AND status='awaiting_review'");
  $q->execute([$u['id']]);
  if($q->rowCount()!==1)throw new DomainException('No pending plan-change request to withdraw.');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 auditEvent((int)$u['id'],'billing.payfast.plan_change_withdrawn',null);
 flash('Your plan-change request has been withdrawn. Your subscription remains unchanged.');
 go('app/billing');
}
/** Exact settlement quote must be refreshed by customer confirmation before any gateway update. */
function pfPlanChangeQuoteValid(int $storedUsd,int $storedZar,int $currentUsd,int $currentZar):bool{
 return $storedUsd>0 && $storedZar>=500 && $storedUsd===$currentUsd && $storedZar===$currentZar;
}
if($path==='/payfast/plan-change/confirm'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 if(!pfEnabled()||(pfMode()==='live'&&cfg('PAID_BILLING_ENABLED','0')!=='1')||empty($u['email_verified_at']))throw new DomainException('Subscription changes require verified accounts and approved billing.');
 $id=(int)($_POST['request_id']??0);
 if($id<1)throw new DomainException('Invalid plan change.');
 $db=db();$db->beginTransaction();
 try{
  $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$u['id']]);
  if(!$lock->fetchColumn())throw new DomainException('Account not found.');
  $q=$db->prepare("SELECT * FROM payfast_plan_changes WHERE id=? AND user_id=? AND status='awaiting_review' FOR UPDATE");
  $q->execute([$id,$u['id']]);$request=$q->fetch();
  if(!$request)throw new DomainException('This request is no longer available.');
  $q=$db->prepare('SELECT order_id,plan,status,mode,token FROM payfast_subscriptions WHERE user_id=? FOR UPDATE');
  $q->execute([$u['id']]);$sub=$q->fetch();
  if(!$sub||$sub['mode']!==pfMode()||$sub['status']!=='active'||$sub['order_id']!==$request['order_id']||$sub['plan']!==$request['from_plan']||empty($sub['token']))throw new DomainException('The subscription has changed. Request a new quote.');
  $target=(string)$request['to_plan'];
  $usd=fyndetUsdCents($target);$zar=pfAmount($target);
  if(!pfPlanChangeQuoteValid((int)$request['quoted_usd_cents'],(int)$request['quoted_zar_cents'],$usd,$zar))throw new DomainException('The exchange-rate quote has changed. Withdraw the request and obtain a new quote.');
  $confirmed=(string)($_POST['consent_amount_cents']??'');
  if(!ctype_digit($confirmed)||(int)$confirmed!==$zar)throw new DomainException('Confirm the quoted future recurring amount.');
  // Never persist the new plan before the provider confirms its future amount.
  // Keep the account lock during the provider call to serialize competing requests.
  if(!pfApiUpdateAmount((string)$sub['token'],$zar))throw new DomainException('PayFast did not confirm the new recurring amount. No local plan change was made.');
  $q=$db->prepare("UPDATE payfast_plan_changes SET status='provider_confirmed' WHERE id=? AND status='awaiting_review'");
  $q->execute([$id]);
  if($q->rowCount()!==1)throw new RuntimeException('Request changed during provider confirmation.');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 auditEvent((int)$u['id'],'billing.payfast.plan_change_confirmed',(string)$id);
 flash('PayFast has confirmed the future recurring amount. Your current plan stays active until a verified payment at the new amount.');
 go('app/billing');
}
function pfRenderPlanChangePanel(array $u):void{
 if(!pfEnabled())return;
 $q=db()->prepare('SELECT s.plan,s.status,s.mode,s.token FROM payfast_subscriptions s WHERE s.user_id=? AND s.mode=?');
 $q->execute([$u['id'],pfMode()]);$s=$q->fetch();
 if(!$s||$s['status']!=='active'||empty($s['token']))return;
 $q=db()->prepare("SELECT id,from_plan,to_plan,status,quoted_usd_cents,quoted_zar_cents,requested_at FROM payfast_plan_changes WHERE user_id=? AND status IN ('awaiting_review','provider_confirmed') ORDER BY id DESC LIMIT 1");
 $q->execute([$u['id']]);$pending=$q->fetch();
 echo '<section class="panel" style="margin:18px 0"><h2>Change your subscription</h2>';
 if($pending){
  echo '<p>Requested change: '.esc(ucfirst($pending['from_plan'])).' to '.esc(ucfirst($pending['to_plan'])).'.</p>';
  if($pending['status']==='awaiting_review'){
   echo '<p>Confirm the new recurring amount. Your plan remains unchanged until a verified subsequent payment.</p><form method="post" action="/payfast/plan-change/confirm"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="request_id" value="'.(int)$pending['id'].'"><input type="hidden" name="consent_amount_cents" value="'.(int)$pending['quoted_zar_cents'].'"><p>Requested plan: USD '.esc(number_format((int)$pending['quoted_usd_cents']/100,2)).'/month. PayFast future charge: ZAR '.esc(number_format((int)$pending['quoted_zar_cents']/100,2)).' per month, fixed at this amount until a later authorised change.</p><button class="button button-accent">I agree to this future recurring charge</button></form><form method="post" action="/payfast/plan-change/withdraw"><input type="hidden" name="_csrf" value="'.csrf().'"><button class="button button-outline">Withdraw request</button></form>';
  }else{
   echo '<p>PayFast has confirmed the future amount. Your current benefits remain in place until a verified payment at the new amount.</p>';
  }
 }else{
  echo '<p class="muted">Request an upgrade or downgrade. This request does not change your subscription, payment amount or access. Updated recurring charges require separate confirmation.</p><div class="billing-choices">';
  foreach(['starter','pro','business'] as $tier){
   if($tier===$s['plan'])continue;
   echo '<form method="post" action="/payfast/plan-change"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="plan" value="'.esc($tier).'"><button class="button button-outline">Request '.esc(pfPlanChangeDirection((string)$s['plan'],$tier)).' to '.esc(ucfirst($tier)).' — USD '.esc(number_format(fyndetUsdCents($tier)/100,2)).'/month</button></form>';
  }
  echo '</div>';
 }
 echo '</section>';
}
