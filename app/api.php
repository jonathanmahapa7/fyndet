<?php
declare(strict_types=1);
/** Scoped, revocable owner-only API. Never exposes an investigation of another account. */
function apiReply(int $status,array $data):never {
 http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('X-Robots-Tag: noindex, nofollow');
 echo json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES);exit;
}
function apiAuth():array {
 $header=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
 if(!preg_match('/^Bearer (fyd_[a-f0-9]{64})$/',$header,$m))apiReply(401,['error'=>'Bearer token required']);
 $hash=hash('sha256',$m[1]);
 $q=db()->prepare('SELECT u.id,u.email,u.plan,u.email_verified_at,t.id AS token_id FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked_at IS NULL');
 $q->execute([$hash]);$u=$q->fetch();
 if(!$u)apiReply(403,['error'=>'API token invalid or revoked']);
 $u['plan']=effectivePlan((int)$u['id'],(string)$u['plan']);
 if(empty($u['email_verified_at'])||!in_array($u['plan'],['pro','business'],true))apiReply(403,['error'=>'API access requires a verified Pro or Business account']);
 return $u;
}
if($path==='/app/api-keys/create'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 if(empty($u['email_verified_at'])||!in_array($u['plan'],['pro','business'],true))throw new DomainException('Verified Pro or Business subscription required.');
 $label=trim((string)($_POST['label']??''));
 if(mb_strlen($label)<3||mb_strlen($label)>90)throw new DomainException('API key label must be between 3 and 90 characters.');
 $q=db()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id=? AND revoked_at IS NULL');$q->execute([$u['id']]);
 if((int)$q->fetchColumn()>=5)throw new DomainException('Maximum of five active API keys per account.');
 $token='fyd_'.bin2hex(random_bytes(32));
 $q=db()->prepare('INSERT INTO api_tokens(user_id,label,token_hash) VALUES(?,?,?)');$q->execute([$u['id'],$label,hash('sha256',$token)]);
 auditEvent((int)$u['id'],'api.key.created',(string)db()->lastInsertId());
 $_SESSION['one_time_api_key']=$token;go('app/api-keys');
}
if($path==='/app/api-keys/revoke'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$id=(int)($_POST['id']??0);
 $q=db()->prepare('UPDATE api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL');$q->execute([$id,$u['id']]);
 if($q->rowCount()!==1)throw new DomainException('API key not found or already revoked.');
 auditEvent((int)$u['id'],'api.key.revoked',(string)$id);flash('API key revoked.');go('app/api-keys');
}
if($path==='/app/api-keys'){
 $u=mustUser();pageTop('API keys');
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">DEVELOPER / API ACCESS</p><h1 class="interior-title">A secure <em>connection.</em></h1><p>Tokens are limited to self-check investigations associated with this account. Never share your token or embed it in browser code.</p></div>';
 if(isset($_SESSION['one_time_api_key'])){$key=$_SESSION['one_time_api_key'];unset($_SESSION['one_time_api_key']);echo '<section class="panel"><h2>Copy your new token now</h2><p class="muted">This is the only time it will be shown.</p><div class="mono api-secret">'.esc($key).'</div></section>';}
 $allowed=!empty($u['email_verified_at'])&&in_array($u['plan'],['pro','business'],true);
 echo '<div class="workspace-grid"><section class="panel"><h2>Create a token</h2><form method="post" class="form-stack" action="/app/api-keys/create"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Token label<input name="label" required minlength="3" maxlength="90" placeholder="My secure integration"></label><button class="button button-accent" '.($allowed?'':'disabled').'>Create API key ↗</button></form></section><section class="panel"><h2>Active API keys</h2>';
 $q=db()->prepare('SELECT id,label,created_at FROM api_tokens WHERE user_id=? AND revoked_at IS NULL ORDER BY id DESC');$q->execute([$u['id']]);
 foreach($q->fetchAll() as $key)echo '<form method="post" action="/app/api-keys/revoke"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="id" value="'.(int)$key['id'].'"><strong>'.esc($key['label']).'</strong> <small>'.esc($key['created_at']).'</small> <button class="button button-outline button-small">Revoke</button></form>';
 echo '</section></div><section class="panel" style="margin-top:18px"><h2>API endpoints</h2><p class="mono">POST /api/v1/investigations</p><p class="mono">GET /api/v1/investigations/{id}</p><p class="muted">Send Authorization: Bearer YOUR_TOKEN and application/json; only your verified registered email is eligible. Requests are counted against your monthly credits. This API does not offer bulk account enumeration.</p></section></main>';pageEnd();exit;
}
if($path==='/api/v1/investigations'&&$_SERVER['REQUEST_METHOD']==='POST'){
 $u=apiAuth();$bucket='api-create|'.$u['id'];$limit=$u['plan']==='business'?6:4;if(!authAllowed($bucket,$limit,60))apiReply(429,['error'=>'API rate limit reached']);authFailed($bucket);$raw=file_get_contents('php://input',false,null,0,10000);$input=json_decode($raw,true);
 if(!is_array($input))apiReply(400,['error'=>'JSON body required']);
 $email=strtolower(trim((string)($input['email']??'')));$purpose=trim((string)($input['purpose']??''));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!hash_equals($u['email'],$email)||mb_strlen($purpose)<10||mb_strlen($purpose)>400)apiReply(422,['error'=>'Only verified registered email self-check with valid purpose is allowed']);
 $pdo=db();$pdo->beginTransaction();
 $lockedPlan=lockEntitlement((int)$u['id']);
 if(!in_array($lockedPlan,['pro','business'],true)||used((int)$u['id'])>=credits($lockedPlan)){$pdo->rollBack();apiReply(429,['error'=>'Plan credits exhausted or API unavailable']);}
 $id=bin2hex(random_bytes(16));
 $q=$pdo->prepare('INSERT INTO investigations(id,user_id,target_email,purpose) VALUES(?,?,?,?)');$q->execute([$id,$u['id'],$email,$purpose]);
 $q=$pdo->prepare('INSERT INTO usage_ledger(user_id,investigation_id,period) VALUES(?,?,?)');$q->execute([$u['id'],$id,gmdate('Y-m')]);
 $pdo->commit();auditEvent((int)$u['id'],'api.investigation.queued',substr($id,0,16));
 apiReply(202,['id'=>$id,'status'=>'queued','resource'=>'/api/v1/investigations/'.$id]);
}
if(preg_match('~^/api/v1/investigations/([a-f0-9]{32})$~',$path,$m)){
 if($_SERVER['REQUEST_METHOD']!=='GET')apiReply(405,['error'=>'Method not allowed']);
 $u=apiAuth();$q=db()->prepare('SELECT id,target_email,status,result_json,created_at,finished_at FROM investigations WHERE id=? AND user_id=?');$q->execute([$m[1],$u['id']]);$row=$q->fetch();
 if(!$row)apiReply(404,['error'=>'Investigation not found']);
 $data=['id'=>$row['id'],'email'=>$row['target_email'],'status'=>$row['status'],'created_at'=>$row['created_at'],'finished_at'=>$row['finished_at']];
 if($row['result_json'])$data['findings']=json_decode($row['result_json'],true);
 apiReply(200,$data);
}
if(str_starts_with($path,'/api/'))apiReply(404,['error'=>'Not found']);
