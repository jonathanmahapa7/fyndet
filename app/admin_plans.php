<?php
declare(strict_types=1);
/** Manual complimentary plan grants, distinct from externally billed subscriptions. */
if($path==='/admin/plan-grant'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$admin=platformAdmin();
 $target=(int)($_POST['user_id']??0);
 $plan=(string)($_POST['plan']??'');
 $reason=trim((string)($_POST['reason']??''));
 if($target<1||$target===(int)$admin['id']||!in_array($plan,['free','starter','pro','business'],true)||mb_strlen($reason)<12||mb_strlen($reason)>400)throw new DomainException('Invalid plan change, protected administrator account, or reason too short.');
 $db=db();$db->beginTransaction();
 try {
  $q=$db->prepare('SELECT plan,subscription_status,stripe_customer_id FROM users WHERE id=? FOR UPDATE');$q->execute([$target]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if(!$old)throw new DomainException('Account not found.');
  if(!empty($old['stripe_customer_id'])||!in_array((string)$old['subscription_status'],['free','complimentary'],true))throw new DomainException('Payment-linked accounts must be changed through their payment provider.');
  $status=$plan==='free'?'free':'complimentary';
  $q=$db->prepare('UPDATE users SET plan=?,subscription_status=? WHERE id=?');$q->execute([$plan,$status,$target]);
  auditEvent((int)$admin['id'],'admin.complimentary_plan_changed',(string)$target.':'.$old['plan'].'->'.$plan.' / '.$reason);
  $db->commit();
 } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 flash('Complimentary access updated. No payment was collected or cancelled.');go('admin');
}
function fyndetAdminUsers(array $admin):void {
 $term=mb_substr(trim((string)($_GET['q']??'')),0,120);
 if($term!==''){$q=db()->prepare('SELECT id,email,name,plan,subscription_status,stripe_customer_id,is_platform_admin FROM users WHERE email LIKE ? ORDER BY id DESC LIMIT 50');$q->execute(['%'.$term.'%']);$rows=$q->fetchAll();}
 else $rows=db()->query('SELECT id,email,name,plan,subscription_status,stripe_customer_id,is_platform_admin FROM users ORDER BY id DESC LIMIT 50')->fetchAll();
 echo '<section class="panel" style="margin-top:18px"><h2>User &amp; subscription management</h2><p class="muted">Upgrade or downgrade complimentary plans below. Changes to payment-provider subscriptions must be made with the payment provider. This interface does not charge customers.</p><form method="get" action="/admin" class="form-stack"><label>Search by email<input type="search" name="q" value="'.esc($term).'" placeholder="Customer email"></label><button class="button button-outline">Search</button></form>';
 foreach($rows as $r){
  echo '<article class="evidence-item"><strong>'.esc((string)$r['email']).'</strong><p class="muted">'.esc((string)$r['name']).' · '.esc((string)$r['plan']).' · '.esc((string)$r['subscription_status']).'</p>';
  if((int)$r['id']===(int)$admin['id'])echo '<p class="muted">Current administrator account: protected.</p>';
  elseif(!empty($r['stripe_customer_id'])||!in_array((string)$r['subscription_status'],['free','complimentary'],true))echo '<p class="muted">Payment-managed subscription: change in payment provider, not here.</p>';
  else {
   echo '<form method="post" action="/admin/plan-grant" class="form-stack"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="user_id" value="'.(int)$r['id'].'"><label>Plan<select name="plan">';
   foreach(['free','starter','pro','business'] as $p)echo '<option value="'.$p.'"'.($p===$r['plan']?' selected':'').'>'.ucfirst($p).'</option>';
   echo '</select></label><label>Reason for complimentary grant/change<input type="text" name="reason" required minlength="12" maxlength="400" placeholder="Document reason for this change"></label><button class="button button-outline">Save plan change</button></form>';
  }
  echo '</article>';
 }
 echo '</section>';
}
