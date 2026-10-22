<?php
declare(strict_types=1);
function authAllowed(string $key,int $limit=8,int $window=900):bool{
 $hash=hash('sha256','login|'.$key);$db=db();
 $q=$db->prepare('SELECT attempts,window_ends FROM auth_rate_limits WHERE bucket_hash=?');$q->execute([$hash]);$row=$q->fetch();
 if(!$row||strtotime($row['window_ends'])<time()){
  $q=$db->prepare('INSERT INTO auth_rate_limits(bucket_hash,attempts,window_ends) VALUES(?,0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE attempts=0,window_ends=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)');
  $q->execute([$hash,$window,$window]);return true;
 }
 return (int)$row['attempts']<$limit;
}
function authFailed(string $key):void {
 $hash=hash('sha256','login|'.$key);
 $q=db()->prepare('UPDATE auth_rate_limits SET attempts=attempts+1 WHERE bucket_hash=?');$q->execute([$hash]);
}
function newVerification(int $uid,string $email):void {
 $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
 $q=db()->prepare('DELETE FROM email_verification_tokens WHERE user_id=?');$q->execute([$uid]);
 $q=db()->prepare('INSERT INTO email_verification_tokens(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))');$q->execute([$hash,$uid]);
 $origin=rtrim(cfg('APP_URL'),' /');
 if(!str_starts_with($origin,'https://'))throw new DomainException('HTTPS APP_URL must be configured for account verification.');
 $link=$origin.'/verify-email?token='.$token;
 $subject='Verify your FyndEt email address';
 $message="Welcome to FyndEt.\n\nVerify the address you registered by visiting:\n".$link."\n\nThis link expires in 24 hours. If you did not create an account, you can ignore this email.\n";
 $from=cfg('MAIL_FROM','');
 if(!filter_var($from,FILTER_VALIDATE_EMAIL))throw new DomainException('Verification email delivery is not configured. Contact support.');
 if(!mail($email,$subject,$message,['From'=>$from,'Content-Type'=>'text/plain; charset=UTF-8']))throw new DomainException('Verification email could not be sent. Please contact support.');
}
if($path==='/verify-email'){
 $token=(string)($_GET['token']??'');
 if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new DomainException('This verification link is invalid.');
 $hash=hash('sha256',$token);$db=db();$db->beginTransaction();
 $q=$db->prepare('SELECT user_id FROM email_verification_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');$q->execute([$hash]);$row=$q->fetch();
 if(!$row){$db->rollBack();throw new DomainException('This verification link has expired or was already used.');}
 $q=$db->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$row['user_id']]);
 $q=$db->prepare('DELETE FROM email_verification_tokens WHERE user_id=?');$q->execute([$row['user_id']]);$db->commit();
 flash('Your email is verified. You can now run self-check investigations.');go('app');
}
if($path==='/resend-verification'){
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 verifyCsrf();$u=mustUser();
 if(!empty($u['email_verified_at']))go('app');
 $key='resend|'.$u['id'];
 if(!authAllowed($key,3,3600))throw new DomainException('Too many verification requests. Try again later.');
 authFailed($key);newVerification((int)$u['id'],$u['email']);
 flash('Verification link sent. Check your inbox.');go('app');
}
