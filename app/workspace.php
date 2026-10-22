<?php
declare(strict_types=1);
/** Protected organisation, privacy, audit and API management routes. */
function auditEvent(?int $actor,string $event,?string $subject=null,?int $org=null):void {
 $q=db()->prepare('INSERT INTO audit_events(actor_user_id,organisation_id,event_type,subject_ref) VALUES(?,?,?,?)');
 $q->execute([$actor,$org,$event,$subject]);
}
function myOrganisation(int $uid):?array {
 $q=db()->prepare('SELECT o.id,o.name,o.owner_user_id,m.role FROM organisations o JOIN organisation_members m ON m.organisation_id=o.id WHERE m.user_id=? ORDER BY o.id LIMIT 1');
 $q->execute([$uid]);return $q->fetch()?:null;
}
function orgMembership(int $uid,int $orgId):?array{
 $q=db()->prepare('SELECT o.id,o.name,o.owner_user_id,m.role FROM organisations o JOIN organisation_members m ON m.organisation_id=o.id WHERE m.user_id=? AND o.id=?');
 $q->execute([$uid,$orgId]);return $q->fetch()?:null;
}
if($path==='/app/team/create'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 if($u['plan']!=='business')throw new DomainException('Business subscription required for team creation.');
 if(myOrganisation((int)$u['id']))throw new DomainException('You already belong to an organisation.');
 $name=trim((string)($_POST['name']??''));
 if(mb_strlen($name)<3||mb_strlen($name)>160)throw new DomainException('Organisation name must be 3–160 characters.');
 $db=db();$db->beginTransaction();
 $q=$db->prepare('INSERT INTO organisations(name,owner_user_id) VALUES(?,?)');$q->execute([$name,$u['id']]);$id=(int)$db->lastInsertId();
 $q=$db->prepare("INSERT INTO organisation_members(organisation_id,user_id,role) VALUES(?,?,'owner')");$q->execute([$id,$u['id']]);$db->commit();
 auditEvent((int)$u['id'],'organisation.created',(string)$id,$id);
 flash('Organisation created.');go('app/team');
}
if($path==='/app/team/invite'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$org=myOrganisation((int)$u['id']);
 if(!$org||!in_array($org['role'],['owner','admin'],true)||$u['plan']!=='business')throw new DomainException('Team administration requires an authorised Business administrator.');
 $email=strtolower(trim((string)($_POST['email']??'')));$role=(string)($_POST['role']??'analyst');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['admin','analyst'],true))throw new DomainException('Invalid invited address or role.');
 $q=db()->prepare('SELECT COUNT(*) FROM organisation_members WHERE organisation_id=?');$q->execute([$org['id']]);$members=(int)$q->fetchColumn();
 $q=db()->prepare('SELECT COUNT(*) FROM organisation_invites WHERE organisation_id=? AND expires_at>UTC_TIMESTAMP()');$q->execute([$org['id']]);$invites=(int)$q->fetchColumn();
 if($members+$invites>=3)throw new DomainException('This plan includes three seats. Revoke unused invitations or contact sales for larger teams.');
 $token=bin2hex(random_bytes(32));$q=db()->prepare('INSERT INTO organisation_invites(token_hash,organisation_id,invited_email,role,invited_by,expires_at) VALUES(?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 48 HOUR))');
 $q->execute([hash('sha256',$token),$org['id'],$email,$role,$u['id']]);
 $origin=rtrim(cfg('APP_URL'),' /');
 if(!str_starts_with($origin,'https://')&&cfg('APP_ENV')!=='testing')throw new DomainException('HTTPS application origin is required.');
 sendFyndetNotice($email,'FyndEt organisation invitation',"You were invited to ".$org['name']." on FyndEt.\n\nSign in using this email and visit:\n".$origin.'/app/team/accept?token='.$token."\n\nExpires in 48 hours. If unexpected, ignore this message.");
 auditEvent((int)$u['id'],'organisation.invited',hash('sha256',$email),(int)$org['id']);
 flash('Invitation sent.');go('app/team');
}
if($path==='/app/team/accept'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$token=(string)($_POST['token']??'');
 if(empty($u['email_verified_at']))throw new DomainException('Verify your account email before joining a team.');
 if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new DomainException('Invalid invitation.');
 $db=db();$db->beginTransaction();
 $q=$db->prepare('SELECT * FROM organisation_invites WHERE token_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');$q->execute([hash('sha256',$token)]);$invite=$q->fetch();
 if(!$invite||!hash_equals($invite['invited_email'],$u['email'])){$db->rollBack();throw new DomainException('Invitation does not belong to this account or has expired.');}
 if(myOrganisation((int)$u['id'])){$db->rollBack();throw new DomainException('This user already belongs to an organisation.');}
 $q=$db->prepare('INSERT INTO organisation_members(organisation_id,user_id,role) VALUES(?,?,?)');$q->execute([$invite['organisation_id'],$u['id'],$invite['role']]);
 $q=$db->prepare('DELETE FROM organisation_invites WHERE token_hash=?');$q->execute([$invite['token_hash']]);$db->commit();
 auditEvent((int)$u['id'],'organisation.joined',(string)$invite['organisation_id'],(int)$invite['organisation_id']);flash('You joined the organisation.');go('app/team');
}
if($path==='/app/team/accept'){
 $u=mustUser();pageTop('Team invitation');$token=(string)($_GET['token']??'');
 echo '<main class="section container"><h1 class="interior-title">Accept your <em>invitation.</em></h1><p>Your email must match the invited address.</p><form method="post" action="/app/team/accept"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="token" value="'.esc($token).'"><button class="button button-accent">Join organisation ↗</button></form></main>';pageEnd();exit;
}
if($path==='/app/team'){
 $u=mustUser();$org=myOrganisation((int)$u['id']);pageTop('Team management');
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">WORKSPACE / TEAMS</p><h1 class="interior-title">Your <em>team.</em></h1><p>Controlled collaboration, transparent roles and limited access. Investigations remain personal unless shared access is explicitly developed and reviewed.</p></div>';
 if(!$org){echo '<section class="panel"><h2>Create a business workspace</h2><p class="muted">Business plan required. Three seats are included in the published proposal.</p><form method="post" action="/app/team/create" class="form-stack"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Organisation name<input name="name" required minlength="3" maxlength="160"></label><button class="button button-accent" '.($u['plan']!=='business'?'disabled':'').'>Create workspace ↗</button></form></section>';}
 else{
  echo '<section class="panel"><p class="eyebrow">ORGANISATION</p><h2>'.esc($org['name']).'</h2><p class="muted">Your role: '.esc($org['role']).'</p>';
  $q=db()->prepare('SELECT u.email,u.name,m.role,m.joined_at FROM organisation_members m JOIN users u ON m.user_id=u.id WHERE m.organisation_id=? ORDER BY m.joined_at');$q->execute([$org['id']]);
  echo '<div class="table-scroll"><table><thead><tr><th>MEMBER</th><th>EMAIL</th><th>ROLE</th></tr></thead><tbody>';
  foreach($q->fetchAll() as $row)echo '<tr><td>'.esc($row['name']).'</td><td>'.esc($row['email']).'</td><td>'.esc($row['role']).'</td></tr>';
  echo '</tbody></table></div></section>';
  if(in_array($org['role'],['owner','admin'],true))echo '<section class="panel" style="margin-top:18px"><h2>Invite a colleague</h2><form class="form-stack" method="post" action="/app/team/invite"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Email<input type="email" name="email" required></label><label>Role<select name="role"><option value="analyst">Analyst</option><option value="admin">Administrator</option></select></label><button class="button button-accent" '.($u['plan']!=='business'?'disabled':'').'>Send invitation ↗</button></form></section>';
 }echo '</main>';pageEnd();exit;
}
if($path==='/app/privacy/request'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$type=(string)($_POST['type']??'');$details=trim((string)($_POST['details']??''));
 if(!in_array($type,['access','correction','deletion','objection'],true)||mb_strlen($details)<10||mb_strlen($details)>1500)throw new DomainException('Select a valid privacy request and provide 10–1500 characters of detail.');
 $q=db()->prepare('INSERT INTO privacy_requests(user_id,request_type,details) VALUES(?,?,?)');$q->execute([$u['id'],$type,$details]);
 auditEvent((int)$u['id'],'privacy.request.created',(string)db()->lastInsertId());
 flash('Privacy request submitted for review.');go('app/privacy');
}
if($path==='/app/privacy'){
 $u=mustUser();pageTop('Privacy controls');
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">ACCOUNT / PRIVACY</p><h1 class="interior-title">Your data.<br><em>Your rights.</em></h1><p>Submit access, correction, deletion or objection requests. Sensitive requests are reviewed before irreversible actions.</p></div><div class="workspace-grid"><section class="panel"><h2>Submit a request</h2><form class="form-stack" method="post" action="/app/privacy/request"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Request type<select name="type"><option value="access">Access my data</option><option value="correction">Correct information</option><option value="deletion">Request deletion</option><option value="objection">Object to processing</option></select></label><label>Details<textarea name="details" minlength="10" maxlength="1500" required></textarea></label><button class="button button-accent">Submit request ↗</button></form></section><section class="panel"><h2>Previous requests</h2>';
 $q=db()->prepare('SELECT request_type,status,created_at FROM privacy_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 25');$q->execute([$u['id']]);
 foreach($q->fetchAll() as $req)echo '<p>'.esc(ucfirst($req['request_type'])).' — '.esc($req['status']).'<br><small>'.esc($req['created_at']).' UTC</small></p>';
 echo '</section></div><section class="panel" style="margin-top:18px"><h2>Download your account data</h2><p class="muted">Export your personal investigation history and privacy requests as JSON. This export never includes passwords, payment-card records or another member’s private investigations.</p><a class="button button-outline" href="/app/privacy/export">Download my data ↗</a></section></main>';pageEnd();exit;
}
