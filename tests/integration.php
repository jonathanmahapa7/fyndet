<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$base='http://127.0.0.1:8099';$cookie=tempnam(sys_get_temp_dir(),'fyndet-test-cookie');
function check(bool $ok,string $description):void {
 if(!$ok){fwrite(STDERR,"FAIL: $description\n");exit(1);}echo "PASS: $description\n";
}
function request(string $path,string $method='GET',array $data=[]):array{
 global $base,$cookie;
 $c=curl_init($base.$path);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>18]);
 if($method==='POST')curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
 $r=curl_exec($c);if($r===false)throw new RuntimeException(curl_error($c));
 $status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$split=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);
 return [$status,substr($r,$split),substr($r,0,$split)];
}
function formToken(string $html):string{
 check((bool)preg_match('/name="_csrf" value="([a-f0-9]{64})"/',$html,$m),'CSRF token rendered');
 return $m[1];
}
function lastMailToken():string{
 $lines=file(dirname(__DIR__).'/var/test-mail.log',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
 check((bool)$lines,'Test mail captured');
 $fields=explode("\t",end($lines));$body=base64_decode(end($fields),true);
 check((bool)preg_match('/token=([a-f0-9]{64})/',$body,$m),'Recovery or verification link captured');
 return $m[1];
}
[$status,$body]=request('/');check($status===200&&str_contains($body,'Every address'),'Premium homepage renders');
[$status,$body]=request('/pricing');check($status===200&&str_contains($body,'$33'),'Five-plan pricing page renders');
[$status,$body]=request('/register');$csrf=formToken($body);
[$status,$body,$headers]=request('/register','POST',['_csrf'=>$csrf,'name'=>'Quality Assurance','email'=>'qa@example.com','password'=>'TestingPassword_2026!']);
check($status===303,'Account registration persists user and redirects');
$verification=lastMailToken();[$status]=request('/verify-email?token='.$verification);check($status===303,'Email ownership verification succeeds');
[$status,$body]=request('/app');check($status===200&&str_contains($body,'YOUR INTELLIGENCE WORKSPACE'),'Verified user dashboard renders');
$csrf=formToken($body);
[$status]=request('/investigate','POST',['_csrf'=>$csrf,'target'=>'someone-else@example.com','purpose'=>'Unapproved unrelated account lookup','authorised'=>1]);
check($status===422,'Unauthorised third-party lookup is blocked');
[$status,$body,$headers]=request('/investigate','POST',['_csrf'=>$csrf,'target'=>'qa@example.com','purpose'=>'Review exposure of my own email domain','authorised'=>1]);
check($status===303&&preg_match('/Location: \/app\/report\?id=([a-f0-9]{32})/i',$headers,$m)===1,'Self-check is queued with an opaque identifier');
$id=$m[1];
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/process-investigations.php'),$output,$exit);
check($exit===0,'Cron-compatible CLI worker executes successfully');
[$status,$body]=request('/app/report?id='.$id);
check($status===200&&str_contains($body,'MX')&&str_contains($body,'Public footprint references'),'Completed report displays source-labelled findings');
[$status]=request('/app/reports/export?format=pdf&id='.$id);
check($status===422,'Free-tier paid export is denied server-side');
[$status,$body]=request('/app');$csrf=formToken($body);
[$status]=request('/logout','POST',['_csrf'=>$csrf]);check($status===303,'Session logout succeeds');
[$status,$body]=request('/forgot-password');$csrf=formToken($body);
[$status]=request('/forgot-password','POST',['_csrf'=>$csrf,'email'=>'qa@example.com']);check($status===303,'Password recovery request accepted');
$reset=lastMailToken();
[$status,$body]=request('/reset-password?token='.$reset);$csrf=formToken($body);
[$status]=request('/reset-password','POST',['_csrf'=>$csrf,'token'=>$reset,'password'=>'AnotherVeryStrong_2026!']);
check($status===303,'Password reset consumes single-use token');
[$status,$body]=request('/login');$csrf=formToken($body);
[$status]=request('/login','POST',['_csrf'=>$csrf,'email'=>'qa@example.com','password'=>'AnotherVeryStrong_2026!']);
check($status===303,'Recovered user can sign in');
@unlink($cookie);
echo "ALL TESTS PASSED\n";
