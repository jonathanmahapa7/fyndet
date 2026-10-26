<?php
declare(strict_types=1);
function platformAdmin():array {
 $u=mustUser();if(empty($u['email_verified_at'])){http_response_code(403);pageTop('Email verification required');echo '<main class="section container"><h1>Verify your email address</h1><p>Verify your registered email before accessing administration.</p></main>';pageEnd();exit;}$s=db()->prepare('SELECT is_platform_admin,totp_enabled FROM users WHERE id=?');$s->execute([$u['id']]);
 $authority=$s->fetch();
 if(!$authority||(int)$authority['is_platform_admin']!==1){http_response_code(403);pageTop('Access denied');echo '<main class="section container"><h1>Access denied</h1><p>Administrator permission is required.</p></main>';pageEnd();exit;}
 if((int)$authority['totp_enabled']!==1){http_response_code(403);pageTop('Security requirement');echo '<main class="section container"><h1>Administrator MFA required</h1><p>Enable two-factor authentication before accessing sensitive operations.</p><a class="button button-accent" href="/app/security">Open security settings ↗</a></main>';pageEnd();exit;}
 return $u;
}
require_once __DIR__.'/admin_plans.php';
if($path==='/admin/privacy/resolve'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=platformAdmin();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');$note=trim((string)($_POST['note']??''));
 if($id<1||!in_array($status,['in_review','resolved','rejected'],true)||mb_strlen($note)<10||mb_strlen($note)>1500)throw new DomainException('Invalid review action. Provide a meaningful explanation.');
 $q=db()->prepare("UPDATE privacy_requests SET status=?,reviewed_by=?,resolution_note=?,closed_at=CASE WHEN ? IN ('resolved','rejected') THEN UTC_TIMESTAMP() ELSE NULL END WHERE id=? AND status NOT IN ('resolved','rejected')");
 $q->execute([$status,$u['id'],$note,$status,$id]);if($q->rowCount()!==1)throw new DomainException('Request could not be updated.');
 auditEvent((int)$u['id'],'privacy.request.reviewed',(string)$id);
 flash('Privacy request status updated.');go('admin');
}
if($path==='/admin'){
 $u=platformAdmin();pageTop('Platform administration');
 $count=db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
 $runs=db()->query('SELECT status,COUNT(*) c FROM investigations GROUP BY status')->fetchAll();
 $requests=db()->query("SELECT p.id,p.request_type,p.details,p.status,p.created_at,u.email FROM privacy_requests p JOIN users u ON u.id=p.user_id WHERE p.status IN ('received','in_review') ORDER BY p.created_at ASC LIMIT 30")->fetchAll();
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">FYNDET / PLATFORM ADMINISTRATION</p><h1 class="interior-title">Operational <em>overview.</em></h1><p>Access is limited to explicitly authorised platform administrators.</p></div><div class="workspace-grid"><section class="panel"><p class="eyebrow">REGISTERED ACCOUNTS</p><strong class="quota-number">'.(int)$count.'</strong></section><section class="panel"><p class="eyebrow">JOB STATES</p>';
 foreach($runs as $r)echo '<p>'.esc($r['status']).': '.(int)$r['c'].'</p>';
 echo '</section></div><section class="panel" style="margin-top:18px"><h2>Privacy requests requiring review</h2>';
 if(!$requests)echo '<p class="muted">No open requests.</p>';
 foreach($requests as $r){
 echo '<article class="evidence-item"><p class="eyebrow">'.esc($r['request_type']).' / '.esc($r['status']).'</p><h3>'.esc($r['email']).'</h3><p>'.esc($r['details']).'</p><form class="form-stack" method="post" action="/admin/privacy/resolve"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><label>Review outcome<select name="status"><option value="in_review">In review</option><option value="resolved">Resolved</option><option value="rejected">Rejected (reason required)</option></select></label><label>Resolution note<textarea name="note" required minlength="10" maxlength="1500"></textarea></label><button class="button button-accent">Save review ↗</button></form></article>';
 }
 echo '</section><section class="panel" style="margin-top:18px"><h2>Customers &amp; subscription overview</h2><p class="muted">Live account overview. Paid plans remain controlled by the configured billing provider; no subscription edits are applied here.</p><div class="table-scroll"><table><thead><tr><th>Customer</th><th>Plan</th><th>Billing status</th><th>Joined</th></tr></thead><tbody>';
 $customers=db()->query('SELECT email,name,plan,subscription_status,created_at FROM users ORDER BY id DESC LIMIT 50')->fetchAll();
 foreach($customers as $account)echo '<tr><td>'.esc((string)$account['email']).'</td><td>'.esc((string)$account['plan']).'</td><td>'.esc((string)$account['subscription_status']).'</td><td>'.esc((string)$account['created_at']).'</td></tr>';
 echo '</tbody></table></div></section>';fyndetAdminUsers($u);
 $pfOrders=db()->query("SELECT o.id,o.plan,o.mode,o.status,o.amount_cents,o.created_at,u.email FROM payfast_orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 30")->fetchAll();
 echo '<section class="panel" style="margin-top:18px"><h2>PayFast transactions</h2><p class="muted">Most recent 30 attempts, sandbox and live shown separately. This list is operational; reconcile payments with the PayFast merchant dashboard.</p><div class="table-scroll"><table><thead><tr><th>Customer</th><th>Plan</th><th>Environment</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
 foreach($pfOrders as $entry)echo '<tr><td>'.esc((string)$entry['email']).'</td><td>'.esc((string)$entry['plan']).'</td><td>'.esc((string)$entry['mode']).'</td><td>R'.esc(number_format((int)$entry['amount_cents']/100,2)).'</td><td>'.esc((string)$entry['status']).'</td></tr>';
 echo '</tbody></table></div></section></main>';pageEnd();exit;
}
