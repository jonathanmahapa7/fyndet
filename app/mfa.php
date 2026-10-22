<?php
declare(strict_types=1);
/** cPanel-compatible RFC 6238 TOTP without external network dependencies. */
function mfaKey():string{
 $hex=cfg('APP_KEY');
 if(!preg_match('/^[0-9a-fA-F]{64}$/',$hex))throw new DomainException('Application encryption key must be configured as 64 hexadecimal characters.');
 return hex2bin($hex);
}
function mfaBase32Encode(string $bytes):string{
 $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
 foreach(str_split($bytes) as $char)$bits.=str_pad(decbin(ord($char)),8,'0',STR_PAD_LEFT);
 $output='';foreach(str_split($bits,5) as $part)$output.=$alphabet[bindec(str_pad($part,5,'0'))];
 return $output;
}
function mfaBase32Decode(string $text):string{
 $text=strtoupper(preg_replace('/[^A-Z2-7]/i','',$text));$bits='';
 foreach(str_split($text) as $char){$p=strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$char);if($p===false)return '';$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);}
 $out='';foreach(str_split($bits,8) as $part)if(strlen($part)===8)$out.=chr(bindec($part));
 return $out;
}
function mfaEncrypt(string $secret):string{
 $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',mfaKey(),OPENSSL_RAW_DATA,$iv,$tag);
 if($cipher===false)throw new RuntimeException('Unable to encrypt MFA key.');
 return base64_encode($iv.$tag.$cipher);
}
function mfaDecrypt(string $encoded):string{
 $bytes=base64_decode($encoded,true);
 if($bytes===false||strlen($bytes)<29)throw new RuntimeException('Invalid encrypted MFA record.');
 $value=openssl_decrypt(substr($bytes,28),'aes-256-gcm',mfaKey(),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
 if(!is_string($value))throw new RuntimeException('Unable to decrypt MFA record.');
 return $value;
}
function mfaValue(string $secret,int $step):string{
 $key=mfaBase32Decode($secret);if(strlen($key)<16)throw new RuntimeException('Invalid MFA key.');
 $binary=pack('N2',($step>>32)&0xffffffff,$step&0xffffffff);
 $digest=hash_hmac('sha1',$binary,$key,true);
 $offset=ord(substr($digest,-1))&0x0f;
 $chunk=substr($digest,$offset,4);$num=(unpack('N',$chunk)[1]&0x7fffffff)%1000000;
 return str_pad((string)$num,6,'0',STR_PAD_LEFT);
}
function mfaAccept(int $userId,string $code):bool{
 if(!preg_match('/^[0-9]{6}$/',$code))return false;
 $db=db();$db->beginTransaction();
 try {
  $q=$db->prepare('SELECT totp_secret_encrypted,totp_enabled,totp_last_step FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);$row=$q->fetch();
  if(!$row||!(int)$row['totp_enabled']||empty($row['totp_secret_encrypted'])){$db->rollBack();return false;}
  $secret=mfaDecrypt($row['totp_secret_encrypted']);$now=intdiv(time(),30);$accepted=null;
  foreach([-1,0,1] as $delta){$step=$now+$delta;if($step>(int)$row['totp_last_step']&&hash_equals(mfaValue($secret,$step),$code)){$accepted=$step;break;}}
  if($accepted===null){$db->rollBack();return false;}
  $q=$db->prepare('UPDATE users SET totp_last_step=? WHERE id=?');$q->execute([$accepted,$userId]);$db->commit();return true;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function mfaRecover(int $userId,string $code):bool {
 if(!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i',$code))return false;
 $hash=hash('sha256',strtoupper($code));
 $q=db()->prepare('DELETE FROM totp_recovery_codes WHERE user_id=? AND code_hash=?');
 $q->execute([$userId,$hash]);return $q->rowCount()===1;
}
if($path==='/mfa/challenge'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();
 $id=(int)($_SESSION['pending_2fa_uid']??0);$v=(int)($_SESSION['pending_2fa_sv']??-1);
 if($id<1)throw new DomainException('Authentication session expired. Sign in again.');
 $bucket='mfa|'.$id.'|'.($_SERVER['REMOTE_ADDR']??'');
 if(!authAllowed($bucket,6,900))throw new DomainException('Too many verification attempts. Try again later.');
 $code=trim((string)($_POST['code']??''));$accepted=mfaAccept($id,$code)||mfaRecover($id,$code);
 if(!$accepted){authFailed($bucket);throw new DomainException('Invalid or previously used authenticator/recovery code.');}
 unset($_SESSION['pending_2fa_uid'],$_SESSION['pending_2fa_sv']);
 session_regenerate_id(true);$_SESSION['uid']=$id;$_SESSION['sv']=$v;go('app');
}
if($path==='/mfa/challenge'){
 if(empty($_SESSION['pending_2fa_uid']))go('login');
 pageTop('Two-factor verification');
 echo '<main class="auth-layout container"><section class="auth-intro"><p class="eyebrow">SECURE ACCESS / FYNDET</p><h1>One more <em>step.</em></h1><p>Enter a fresh code from your authenticator app or one unused recovery code.</p></section><section class="auth-panel"><h2>Verify your identity</h2><form class="form-stack" action="/mfa/challenge" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" required autofocus maxlength="16"></label><button class="button button-accent">Verify and sign in ↗</button></form></section></main>';pageEnd();exit;
}
if($path==='/app/security/start'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();if(empty($u['email_verified_at']))throw new DomainException('Verify your email first.');
 $secret=mfaBase32Encode(random_bytes(20));$_SESSION['mfa_setup_secret']=$secret;go('app/security');
}
if($path==='/app/security/confirm'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$secret=(string)($_SESSION['mfa_setup_secret']??'');$code=(string)($_POST['code']??'');
 if(strlen($secret)<20||!preg_match('/^[0-9]{6}$/',$code))throw new DomainException('Create a setup code before confirming.');
 $now=intdiv(time(),30);$match=false;foreach([-1,0,1] as $d)if(hash_equals(mfaValue($secret,$now+$d),$code)){$match=true;break;}
 if(!$match)throw new DomainException('Authenticator code does not match. Confirm your device time.');
 $db=db();$db->beginTransaction();
 $q=$db->prepare('UPDATE users SET totp_secret_encrypted=?,totp_enabled=1,totp_last_step=-1 WHERE id=? AND totp_enabled=0');
 $q->execute([mfaEncrypt($secret),$u['id']]);if($q->rowCount()!==1){$db->rollBack();throw new DomainException('MFA is already enabled.');}
 $q=$db->prepare('DELETE FROM totp_recovery_codes WHERE user_id=?');$q->execute([$u['id']]);$codes=[];
 for($i=0;$i<8;$i++){$code=strtoupper(substr(bin2hex(random_bytes(6)),0,12));$formatted=implode('-',str_split($code,4));$codes[]=$formatted;$q=$db->prepare('INSERT INTO totp_recovery_codes(user_id,code_hash) VALUES(?,?)');$q->execute([$u['id'],hash('sha256',$formatted)]);}
 $db->commit();unset($_SESSION['mfa_setup_secret']);$_SESSION['mfa_recovery_display']=$codes;
 auditEvent((int)$u['id'],'account.mfa.enabled');go('app/security');
}
if($path==='/app/security/disable'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();$code=trim((string)($_POST['code']??''));
 if(!mfaAccept((int)$u['id'],$code)&&!mfaRecover((int)$u['id'],$code))throw new DomainException('A valid current MFA or recovery code is required.');
 $q=db()->prepare('UPDATE users SET totp_secret_encrypted=NULL,totp_enabled=0,totp_last_step=-1 WHERE id=?');$q->execute([$u['id']]);
 $q=db()->prepare('DELETE FROM totp_recovery_codes WHERE user_id=?');$q->execute([$u['id']]);
 auditEvent((int)$u['id'],'account.mfa.disabled');flash('Two-factor authentication disabled.');go('app/security');
}
if($path==='/app/security'){
 $u=mustUser();$q=db()->prepare('SELECT totp_enabled FROM users WHERE id=?');$q->execute([$u['id']]);$enabled=(int)$q->fetchColumn()===1;
 pageTop('Account security');
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">ACCOUNT / SECURITY</p><h1 class="interior-title">Secure your <em>workspace.</em></h1><p>Two-factor authentication uses time-limited codes stored in an authenticator app. Secret keys are encrypted server-side.</p></div><section class="panel" style="max-width:760px"><h2>'.($enabled?'Authenticator protection enabled':'Enable two-factor authentication').'</h2>';
 if(!empty($_SESSION['mfa_recovery_display'])){$codes=$_SESSION['mfa_recovery_display'];unset($_SESSION['mfa_recovery_display']);echo '<div class="flash"><strong>Save these one-time recovery codes securely. They will not appear again.</strong><pre class="mfa-codes">'.esc(implode("\n",$codes)).'</pre></div>';}
 if(!$enabled){
  if(!empty($_SESSION['mfa_setup_secret'])){
   $secret=$_SESSION['mfa_setup_secret'];$uri='otpauth://totp/FyndEt:'.rawurlencode($u['email']).'?secret='.rawurlencode($secret).'&issuer=FyndEt&algorithm=SHA1&digits=6&period=30';
   echo '<p>In your authenticator app, add an account using the following setup key or manual OTP URI.</p><p class="mono api-secret">'.esc($secret).'</p><details><summary>Manual configuration URI</summary><p class="mono">'.esc($uri).'</p></details><form class="form-stack" action="/app/security/confirm" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Current six-digit code<input name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6"></label><button class="button button-accent">Verify and enable ↗</button></form>';
  }else echo '<form action="/app/security/start" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><button class="button button-accent">Set up authenticator ↗</button></form>';
 }else echo '<p class="muted">Your next sign-in will require your authenticator or an unused recovery code.</p><form class="form-stack" action="/app/security/disable" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Current code to disable<input name="code" required></label><button class="button button-outline">Disable two-factor authentication</button></form>';
 echo '</section></main>';pageEnd();exit;
}
