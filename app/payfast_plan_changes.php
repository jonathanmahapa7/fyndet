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
  $q=$db->prepare("SELECT id FROM payfast_plan_changes WHERE user_id=? AND status='awaiting_review' LIMIT 1 FOR UPDATE");$q->execute([$u['id']]);
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
function pfRenderPlanChangePanel(array $u):void{
 if(!pfEnabled())return;
 $q=db()->prepare('SELECT s.plan,s.status,s.mode,s.token FROM payfast_subscriptions s WHERE s.user_id=? AND s.mode=?');
 $q->execute([$u['id'],pfMode()]);$s=$q->fetch();
 if(!$s||$s['status']!=='active'||empty($s['token']))return;
 $q=db()->prepare("SELECT from_plan,to_plan,status,requested_at FROM payfast_plan_changes WHERE user_id=? AND status='awaiting_review' ORDER BY id DESC LIMIT 1");
 $q->execute([$u['id']]);$pending=$q->fetch();
 echo '<section class="panel" style="margin:18px 0"><h2>Change your subscription</h2>';
 if($pending){
  echo '<p>A request to change from '.esc(ucfirst($pending['from_plan'])).' to '.esc(ucfirst($pending['to_plan'])).' is awaiting review. Your current subscription and charges are unchanged.</p>';
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
