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
function signedWebhook(array $event,string $secret='whsec_test_secret'):array{
 global $base;
 $json=json_encode($event,JSON_UNESCAPED_SLASHES);$time=time();
 $digest=hash_hmac('sha256',$time.'.'.$json,$secret);
 $c=curl_init($base.'/billing/webhook');
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Stripe-Signature: t='.$time.',v1='.$digest],CURLOPT_TIMEOUT=>12]);
 $response=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
 return [$status,(string)$response];
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
$event=['id'=>'evt_integration_pro_001','type'=>'customer.subscription.updated','data'=>['object'=>['customer'=>'cus_integration_001','metadata'=>['user_id'=>'1'],'items'=>['data'=>[['price'=>['id'=>'price_integration_pro']]]],'status'=>'active']]];
[$webhookStatus]=signedWebhook($event);check($webhookStatus===200,'Signed billing webhook accepts legitimate subscription event');
[$repeatStatus]=signedWebhook($event);check($repeatStatus===200,'Webhook event replay is idempotent');
[$status,$csv]=request('/app/reports/export?format=csv&id='.$id);
check($status===200&&str_contains($csv,'Public DNS'),'Pro entitlement unlocks CSV evidence export');
[$status,$pdf]=request('/app/reports/export?format=pdf&id='.$id);
check($status===200&&str_starts_with($pdf,'%PDF-'),'Pro entitlement unlocks actual PDF report');

[$status,$body]=request('/robots.txt');
check($status===200&&str_contains($body,'Disallow: /'),'Test deployments are not crawlable');
[$status,$body]=request('/llms.txt');
check($status===200&&str_contains($body,'FyndEt'),'AI discovery guide renders');
[$status,$body]=request('/app/api-keys');check($status===200&&str_contains($body,'Create a token'),'Pro API key screen renders');
$csrf=formToken($body);
[$status,$body,$headers]=request('/app/api-keys/create','POST',['_csrf'=>$csrf,'label'=>'QA secure API client']);
check($status===303,'Owner may generate a scoped API key');
[$status,$body]=request('/app/api-keys');
check(preg_match('/fyd_[a-f0-9]{64}/',$body,$km)===1,'API token shown only at creation');
$apiKey=$km[0];
$h=curl_init('http://127.0.0.1:8099/api/v1/investigations');
curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['email'=>'someone@example.com','purpose'=>'Attempt to search an unrelated email address']),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json']]);
$body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
check($status===422,'API blocks third-party email investigation');
[$status,$body]=request('/app/privacy');
check($status===200&&str_contains($body,'Your data.'),'Account privacy page renders');
$csrf=formToken($body);
[$status,$body,$headers]=request('/app/privacy/request','POST',['_csrf'=>$csrf,'type'=>'access','details'=>'Please provide access to the information held for my account.']);
check($status===303,'Privacy request is persisted');
[$status,$body]=request('/app/team');
check($status===200&&str_contains($body,'Create a business workspace'),'Team entitlement page renders');

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
