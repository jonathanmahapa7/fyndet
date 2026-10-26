<?php
declare(strict_types=1);
require_once __DIR__.'/mount.php';
date_default_timezone_set('UTC');
// cPanel can run a split document root: public files in public_html, app files kept private.
$requestedRoot=(string)(getenv('FYNDET_APP_ROOT')?:'');
$root=$requestedRoot!==''&&is_dir($requestedRoot)&&is_file(rtrim($requestedRoot,'/').'/app/identity.php')
  ? (string)realpath($requestedRoot) : dirname(__DIR__);
function envLoad(string $path): array {
  if(!is_file($path)) return [];
  $out=[];
  foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $line=trim($line); if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
    [$k,$v]=explode('=',$line,2);$out[trim($k)]=trim($v," \t\n\r\0\x0B\"'");
  }return $out;
}
$env=envLoad($root.'/.env');
function cfg(string $key,string $default=''):string {global $env;return (string)($env[$key] ?? (getenv($key) !== false ? getenv($key) : $default));}
if(PHP_SAPI!=='cli'){
 $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
 session_name('fyndet_session');
 session_set_cookie_params(['lifetime'=>0,'path'=>(FYNDET_MOUNT?:'/'),'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
 session_start();
 header('Cache-Control: no-store');
 header('X-Content-Type-Options: nosniff');
 $GLOBALS['csp_nonce']=base64_encode(random_bytes(18));
 header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; style-src \'self\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; script-src \'self\' \'nonce-'. $GLOBALS['csp_nonce'] .'\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'');
}
function db():PDO {static $pdo=null;if(!$pdo){$dsn='mysql:host='.cfg('DB_HOST','localhost').';port='.cfg('DB_PORT','3306').';dbname='.cfg('DB_NAME').';charset=utf8mb4';$pdo=new PDO($dsn,cfg('DB_USER'),cfg('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $pdo;}
function esc(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path=''):string{return FYNDET_MOUNT.'/'.ltrim($path,'/');}
function go(string $path):never {header('Location: '.url($path),true,303);exit;}
function csrf():string {if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verifyCsrf():void {if(!hash_equals(csrf(),(string)($_POST['_csrf']??''))) {http_response_code(419);exit('Invalid session token. Please go back, reload and try again.');}}
function activeBusinessOrg(int $userId):?array {
 $q=db()->prepare("SELECT o.id,o.owner_user_id FROM organisation_members m JOIN organisations o ON o.id=m.organisation_id JOIN users owner ON owner.id=o.owner_user_id WHERE m.user_id=? AND owner.plan='business' AND owner.subscription_status IN ('active','trialing') ORDER BY o.id LIMIT 1");
 $q->execute([$userId]);return $q->fetch()?:null;
}
function effectivePlan(int $userId,string $personalPlan):string {
 return activeBusinessOrg($userId)?'business':$personalPlan;
}
function lockEntitlement(int $userId):string {
 // Called inside an active SQL transaction: lock the shared organisation owner
 // so multiple concurrent team searches cannot consume the final credit twice.
 $org=activeBusinessOrg($userId);$lockingId=$org?(int)$org['owner_user_id']:$userId;
 $q=db()->prepare('SELECT plan FROM users WHERE id=? FOR UPDATE');$q->execute([$lockingId]);$record=$q->fetch();
 if(!$record)throw new DomainException('Account not available.');
 return $org?'business':(string)$record['plan'];
}
function user():?array {
 if(empty($_SESSION['uid']))return null;
 $q=db()->prepare('SELECT id,email,name,plan,subscription_status,email_verified_at,session_version FROM users WHERE id=?');$q->execute([$_SESSION['uid']]);$r=$q->fetch();
 if(!$r||(int)$r['session_version']!==(int)($_SESSION['sv']??-1))return null;
 $r['personal_plan']=$r['plan'];
 $r['plan']=effectivePlan((int)$r['id'],(string)$r['plan']);
 return $r;
}
function mustUser():array {$u=user();if(!$u)go('login');return $u;}
function flash(?string $message=null):string {if($message!==null){$_SESSION['flash']=$message;return '';} $s=$_SESSION['flash']??'';unset($_SESSION['flash']);return $s;}
function credits(string $plan):int{return ['free'=>2,'starter'=>25,'pro'=>100,'business'=>250][$plan]??2;}
function used(int $id):int {
 $org=activeBusinessOrg($id);
 if($org){
  $s=db()->prepare('SELECT COUNT(*) FROM usage_ledger l JOIN organisation_members m ON m.user_id=l.user_id WHERE m.organisation_id=? AND l.period=?');
  $s->execute([(int)$org['id'],gmdate('Y-m')]);
 }else{
  $s=db()->prepare('SELECT COUNT(*) FROM usage_ledger WHERE user_id=? AND period=?');
  $s->execute([$id,gmdate('Y-m')]);
 }
 return (int)$s->fetchColumn();
}
function pageTop(string $title,bool $app=false):void {
 $u=user();$title=esc($title);$notice=flash();
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
 $publicRoutes=[
  '/'=>['Email Digital Footprint Investigation','Discover evidence-backed domain signals, permitted public traces and authorised breach exposure related to your own email address.'],
  '/pricing'=>['Plans and Pricing','Compare Free, Starter, Pro and Business investigation credits and the available subscription features.'],
  '/how-it-works'=>['How FyndEt Works','Explore how FyndEt verifies email ownership, checks approved sources, qualifies evidence and reports uncertainty.'],
  '/contact'=>['Contact and Custom Plans','Contact FyndEt about organisation requirements, custom plans and responsible intelligence use.'],
  '/legal'=>['Privacy and Responsible Use','Understand FyndEt privacy commitments, lawful-use restrictions and source transparency.']
 ];
 $indexable=array_key_exists($path,$publicRoutes);
 $origin=rtrim(cfg('APP_URL',''),' /');
 $trustedOrigin=preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/fyndet)?$~i',$origin)===1?$origin:'';
 $description=$publicRoutes[$path][1]??'A private, evidence-led email intelligence workspace.';
 $pageTitle=$indexable?$publicRoutes[$path][0]:$title;
 if(!$indexable)header('X-Robots-Tag: noindex, nofollow, noarchive');
 echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#08151e"><meta name="description" content="'.esc($description).'"><meta name="robots" content="'.($indexable&&$trustedOrigin?'index,follow,max-image-preview:large':'noindex,nofollow').'"><title>'.esc($pageTitle).' | FyndEt</title>';
 if($trustedOrigin&&$indexable){
  $canonical=$trustedOrigin.($path==='/'?'/':$path);
  echo '<link rel="canonical" href="'.esc($canonical).'"><meta property="og:type" content="website"><meta property="og:site_name" content="FyndEt"><meta property="og:title" content="'.esc($pageTitle).'"><meta property="og:description" content="'.esc($description).'"><meta property="og:url" content="'.esc($canonical).'"><meta name="twitter:card" content="summary">';
  $schema=['@context'=>'https://schema.org','@type'=>'WebSite','name'=>'FyndEt','url'=>$trustedOrigin.'/','description'=>'Evidence-led email and digital-footprint investigations with verified self-check restrictions.'];
  if($path==='/'){
   echo '<script type="application/ld+json" nonce="'.esc($GLOBALS['csp_nonce']??'').'">'.json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
   $faq=['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>[
    ['@type'=>'Question','name'=>'Can FyndEt discover every online account?','acceptedAnswer'=>['@type'=>'Answer','text'=>'No. Coverage depends on authorised source availability. FyndEt presents source-backed observations and states uncertainty.']],
    ['@type'=>'Question','name'=>'Can I investigate any email address?','acceptedAnswer'=>['@type'=>'Answer','text'=>'Current investigations are restricted to the user’s verified registered email. Third-party investigations require additional authority review.']],
    ['@type'=>'Question','name'=>'Is breach data available?','acceptedAnswer'=>['@type'=>'Answer','text'=>'Breach metadata can only be checked where a licensed approved provider is configured and email ownership is verified. Passwords and raw leaked records are not disclosed.']]
   ]];
   echo '<script type="application/ld+json" nonce="'.esc($GLOBALS['csp_nonce']??'').'">'.json_encode($faq,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
  }
 }
 echo '<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg"><link rel="stylesheet" href="/assets/style.css"><script defer src="/assets/app.js"></script></head><body>';

 echo '<div class="site-shell"><header class="site-header"><div class="container header-in"><a class="brand" href="/" aria-label="FyndEt home"><span class="brand-mark"><span></span><span></span><span></span><span></span></span><span>fynd<span class="brand-accent">et</span><sup>®</sup></span></a><nav class="desktop-nav" aria-label="Primary"><a href="/#platform">Platform</a><a href="/how-it-works">How it works</a><a href="/pricing">Pricing</a><a href="/#faq">FAQs</a></nav><div class="nav-actions">';
 if($u) echo '<a class="text-link" href="/app">Dashboard</a><form method="post" action="/logout"><input type="hidden" name="_csrf" value="'.csrf().'"><button class="button button-small button-outline" type="submit">Sign out <span>↗</span></button></form>';
 else echo '<a class="text-link" href="/login">Log in</a><a class="button button-small button-accent" href="/register">Get started <span>↗</span></a>';
 echo '</div><button class="menu-toggle" data-menu-toggle aria-expanded="false" aria-controls="mobileNav" aria-label="Toggle navigation">☰</button></div><nav id="mobileNav" class="mobile-nav" aria-label="Mobile"><a href="/#platform">Platform</a><a href="/how-it-works">How it works</a><a href="/pricing">Pricing</a><a href="/login">Log in</a><a href="/register">Get started</a></nav></header>';
 if($notice)echo '<div class="container"><p role="status" class="flash">'.esc($notice).'</p></div>';
}
function pageEnd():void {echo '<footer class="footer"><div class="container footer-inner"><a class="brand" href="/"><span class="brand-mark"><span></span><span></span><span></span><span></span></span><span>fynd<span class="brand-accent">et</span></span></a><p>Intelligence, with evidence.</p><div class="footer-links"><a href="/pricing">Pricing</a><a href="/legal">Privacy &amp; terms</a><a href="/contact">Contact</a></div><small>© <span data-year>2026</span> FyndEt</small></div></footer></div></body></html>';}
function card(string $n,string $price,string $desc,array $items,bool $featured=false):void{
 $destination=$n==='Custom'?'/contact':($n==='Free'?'/register':(user()?'/app/billing?plan='.strtolower($n):'/register?plan='.strtolower($n)));
 echo '<article class="price-card'.($featured?' featured':'').'">'.($featured?'<span class="popular">MOST POPULAR</span>':'').'<p class="eyebrow">'.$n.'</p><h3>'.$price.'<small>'.($price==='Custom'?'':' /mo').'</small></h3><p class="muted">'.$desc.'</p><a class="button '.($featured?'button-accent':'button-outline').'" href="'.esc($destination).'">'.($n==='Custom'?'Contact sales':'Get started').' <span>↗</span></a><ul>';
 foreach($items as $i)echo '<li><span class="tick">✓</span>'.esc($i).'</li>';echo '</ul></article>';
}
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
try {
 require $root.'/app/identity.php';
 require $root.'/app/billing.php';
 require $root.'/app/report_export.php';
 require $root.'/app/workspace.php';
 require $root.'/app/administration.php';
 require $root.'/app/api.php';
 require $root.'/app/mfa.php';
 require $root.'/app/privacy_data.php';
 require $root.'/app/monitoring.php';
 if($path==='/robots.txt'){
  header('Content-Type: text/plain; charset=UTF-8');
  $origin=rtrim(cfg('APP_URL',''),' /');
  $configured=preg_match('~^https://[a-z0-9.-]+(?::443)?$~i',$origin)===1;
  echo "User-agent: *\n";
  if(!$configured || cfg('APP_ENV','production')!=='production')echo "Disallow: /\n";
  else {
   echo "Allow: /\n";
   foreach(['/app','/billing','/login','/register','/forgot-password','/reset-password','/verify-email','/resend-verification','/investigate','/logout'] as $private)echo "Disallow: $private\n";
   echo "Sitemap: $origin/sitemap.xml\n";
  }
  exit;
 }
 if($path==='/sitemap.xml'){
  header('Content-Type: application/xml; charset=UTF-8');
  $origin=rtrim(cfg('APP_URL',''),' /');
  if(!preg_match('~^https://[a-z0-9.-]+(?::443)?$~i',$origin)||cfg('APP_ENV','production')!=='production'){http_response_code(503);exit('<?xml version="1.0"?><error>Production domain not configured</error>');}
  echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
  foreach(['/','/pricing','/how-it-works','/contact','/legal'] as $route)echo '<url><loc>'.esc($origin.$route).'</loc></url>';
  echo '</urlset>';exit;
 }
 if($path==='/llms.txt'){
  header('Content-Type: text/plain; charset=UTF-8');
  echo "# FyndEt\n\nFyndEt provides authorised, evidence-led email footprint self-checks and domain intelligence. Sources differ in availability and coverage; missing results do not prove an account does not exist. Paid plans are subject to activation and provider licensing.\n\n";
  foreach(['/how-it-works'=>'How it works','/pricing'=>'Pricing','/legal'=>'Privacy and responsible use','/contact'=>'Contact'] as $route=>$label)echo "- $label: $route\n";
  echo "\nPrivate investigation pages are not intended for search or AI indexing.\n";exit;
 }
 if($path==='/logout'&&$_SERVER['REQUEST_METHOD']==='POST'){verifyCsrf();$_SESSION=[];session_regenerate_id(true);go('');}
 if($path==='/register'&&$_SERVER['REQUEST_METHOD']==='POST'){
  verifyCsrf();$signupBucket='signup|'.($_SERVER['REMOTE_ADDR']??'unknown');if(!authAllowed($signupBucket,5,3600))throw new DomainException('Too many registrations from this connection. Please try again later.');authFailed($signupBucket);$email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['name']??''));$pass=(string)($_POST['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($name)>120||mb_strlen($name)<2||strlen($pass)<12)throw new DomainException('Use a valid email address, a name, and a password of at least 12 characters.');
  $q=db()->prepare('INSERT INTO users(email,name,password_hash) VALUES (?,?,?)');
  try{$q->execute([$email,$name,password_hash($pass,PASSWORD_DEFAULT)]);}catch(PDOException $e){throw new DomainException('This email may already be registered. Try signing in.');}
  session_regenerate_id(true);$_SESSION['uid']=(int)db()->lastInsertId();$_SESSION['sv']=0;newVerification((int)$_SESSION['uid'],$email);flash('Check your inbox and verify your email before running an investigation.');go('app');
 }
 if($path==='/login'&&$_SERVER['REQUEST_METHOD']==='POST'){
  verifyCsrf();$bucket=hash('sha256',strtolower(trim((string)($_POST['email']??''))).'|'.($_SERVER['REMOTE_ADDR']??'unknown'));if(!authAllowed($bucket))throw new DomainException('Too many sign-in attempts. Please try again later.');$q=db()->prepare('SELECT id,password_hash,session_version,totp_enabled FROM users WHERE email=?');$q->execute([strtolower(trim((string)($_POST['email']??'')))]);$r=$q->fetch();
  if(!$r||!password_verify((string)($_POST['password']??''),$r['password_hash'])){authFailed($bucket);throw new DomainException('Incorrect email or password.');}
  session_regenerate_id(true);if((int)$r['totp_enabled']===1){$_SESSION['pending_2fa_uid']=(int)$r['id'];$_SESSION['pending_2fa_sv']=(int)$r['session_version'];unset($_SESSION['uid'],$_SESSION['sv']);go('mfa/challenge');}$_SESSION['uid']=(int)$r['id'];$_SESSION['sv']=(int)$r['session_version'];go('app');
 }
 if($path==='/investigate'&&$_SERVER['REQUEST_METHOD']==='POST'){
  verifyCsrf();$u=mustUser();if(empty($u['email_verified_at']))throw new DomainException('Please verify your registered email address first.');$target=strtolower(trim((string)($_POST['target']??'')));$purpose=trim((string)($_POST['purpose']??''));
  if(!filter_var($target,FILTER_VALIDATE_EMAIL)||strlen($purpose)<10||strlen($purpose)>400)throw new DomainException('Enter a valid address and a purpose between 10 and 400 characters.');
  if(!hash_equals($u['email'],$target))throw new DomainException('Self-check mode currently supports only your registered email address. Third-party investigations require a separate authorised verification workflow.');
  if(empty($_POST['authorised']))throw new DomainException('You must confirm that you are authorised to investigate this address.');
  $pdo=db();$pdo->beginTransaction();
  $lockedPlan=lockEntitlement((int)$u['id']);
  if(used((int)$u['id'])>=credits($lockedPlan)){$pdo->rollBack();throw new DomainException('Your monthly investigation credits are used. Contact support to discuss an upgrade.');}
  $id=bin2hex(random_bytes(16));$q=$pdo->prepare('INSERT INTO investigations(id,user_id,target_email,purpose) VALUES(?,?,?,?)');$q->execute([$id,$u['id'],$target,$purpose]);
  $q=$pdo->prepare('INSERT INTO usage_ledger(user_id,investigation_id,period) VALUES(?,?,?)');$q->execute([$u['id'],$id,gmdate('Y-m')]);$pdo->commit();flash('Investigation queued. The cPanel cron worker will process it.');go('app/report?id='.$id);
 }
 if($path==='/'){pageTop('Digital footprint intelligence');?>
 <main><section class="hero"><div class="hero-grid container"><div class="hero-copy" data-reveal><p class="eyebrow"><span class="signal-dot"></span> THE INTELLIGENCE LAYER FOR EMAIL</p><h1>Every address<br>leaves a <em>trace.</em></h1><p class="hero-sub">Go beyond verification. Explore the digital signals associated with your email, understand exposure, and make informed decisions — backed by evidence, not assumptions.</p><div class="hero-actions"><a class="button button-accent" href="/register">Start investigating <span>↗</span></a><a href="/how-it-works" class="button button-ghost">Explore the platform <span>→</span></a></div><div class="hero-proof"><span class="proof-icon">✳</span><div><strong>Built for clarity. Designed for trust.</strong><small>Evidence-led insights · Responsible by design</small></div></div></div><div class="hero-visual" data-reveal><div class="ambient-orbit orbit-one"></div><div class="ambient-orbit orbit-two"></div><div class="scan-card"><div class="scan-top"><span><span class="signal-dot"></span> INVESTIGATION OVERVIEW</span><span class="sample-tag">ILLUSTRATIVE DEMO</span></div><div class="identity"><div class="identity-symbol">@</div><div><small>SUBJECT EMAIL</small><strong>alex@example.com</strong></div><span class="icon-check">✓</span></div><div class="scan-lines"><div><span>DOMAIN INTELLIGENCE</span><b>AVAILABLE</b></div><div class="bar"><i style="width:86%"></i></div><div><span>PUBLIC EVIDENCE</span><b>SOURCE-DEPENDENT</b></div><div class="bar"><i style="width:53%"></i></div><div><span>BREACH EXPOSURE</span><b>PERMISSION-REQUIRED</b></div><div class="bar"><i style="width:31%"></i></div></div><div class="scan-bottom"><span class="sample-spark">✳</span> Uncertainty is part of the intelligence.</div></div><div class="floating-note"><span class="signal-dot"></span> EVIDENCE, NOT GUESSWORK</div></div></div></section><section class="proof-strip"><div class="container strip-inner"><span>INTELLIGENCE WITH INTEGRITY</span><span>✦ SOURCE TRANSPARENCY</span><span>✦ PRIVACY BY DESIGN</span><span>✦ ACTIONABLE CONTEXT</span></div></section><section id="platform" class="section container"><div class="section-heading"><p class="eyebrow">A DIFFERENT DEPTH OF UNDERSTANDING</p><h2>More than a lookup.<br><em>A clearer picture.</em></h2><p>Thoughtful intelligence tools designed to connect reliable observations and preserve the context behind every result.</p></div><div class="feature-grid"><article class="feature-card large"><span class="feature-icon">◎</span><p class="eyebrow">01 / INVESTIGATIONS</p><h3>Follow the evidence.</h3><p>Bring domain signals, permitted public references and authorised exposure data into one investigation workspace.</p><div class="mini-evidence"><span>OBSERVATION</span><strong>Source linked <span>↗</span></strong><small>Retrieved with attribution</small></div></article><article class="feature-card"><span class="feature-icon">◈</span><p class="eyebrow">02 / EXPOSURE</p><h3>Know what surfaced.</h3><p>Understand breach-exposure indicators where a verified, approved source allows access.</p></article><article class="feature-card"><span class="feature-icon">⌘</span><p class="eyebrow">03 / CLARITY</p><h3>Context changes everything.</h3><p>Distinguish confirmed observations, possible matches and sources not checked.</p></article></div></section><section class="statement-section"><div class="container statement-inner"><p class="eyebrow">WHY FYNDET</p><h2>The most important thing in an investigation isn't how much you find. <em>It's what you can verify.</em></h2><a class="under-link" href="/how-it-works">How our approach works ↗</a></div></section><section class="section container" id="pricing"><div class="section-heading centered"><p class="eyebrow">STRAIGHTFORWARD PRICING</p><h2>Intelligence that<br><em>scales with you.</em></h2><p>Start small, go deeper when you need to. Every plan respects the same privacy and evidence standards.</p></div><div class="price-grid"><?php card('Free','$0','Explore the fundamentals',['2 investigations / month','Domain intelligence','Personal use only']);card('Starter','$15','For independent research',['25 investigations / month','Standard permitted sources','PDF exports']);card('Pro','$25','For deeper analysis',['100 investigations / month','Extended permitted sources','CSV and PDF exports'],true);card('Business','$33','For collaborative teams',['250 investigations / month','3 team seats proposed','Organisation administration']);card('Custom','Custom','For complex requirements',['Tailored credits and seats','Contracted integrations','Dedicated support']);?></div><p class="pricing-note">Feature allowances are proposed. Third-party sources and paid checkout activate only once integrations are configured and approved.</p></section><section id="faq" class="section container faq-section"><div><p class="eyebrow">GOOD QUESTIONS, CLEAR ANSWERS</p><h2>What you should<br><em>know.</em></h2></div><div class="faqs"><details open><summary>Can FyndEt discover every online account?</summary><p>No. Platform availability and lawful access vary. We only present source-backed observations and clearly indicate uncertainty.</p></details><details><summary>Can I investigate any email address?</summary><p>The current application supports self-checks using your registered email. Expanded authorised investigations require additional verification and safeguards.</p></details><details><summary>Is breach data available?</summary><p>Only where a licensed provider has been configured and the user has the necessary authority. We do not expose passwords or raw leaked credentials.</p></details><details><summary>When am I charged?</summary><p>Plans are published for comparison. Paid checkout must be connected and verified before purchases can be accepted.</p></details></div></section><section class="cta-band"><div class="container cta-content"><div><p class="eyebrow">YOUR INVESTIGATION STARTS HERE</p><h2>See beyond<br>the address.</h2></div><a href="/register" class="button button-dark">Create your free account ↗</a></div></section></main>
 <?php pageEnd();exit;}
 if($path==='/contact'){
  pageTop('Contact FyndEt');
  $sales=cfg('SALES_EMAIL','');
  echo '<main class="section container"><div class="section-heading"><p class="eyebrow">FYNDET / CONTACT</p><h1 class="interior-title">Explore <em>intelligence.</em></h1><p>For enterprise requirements, licensing questions, commercial enquiries or responsible-use concerns, contact the FyndEt team.</p></div><section class="feature-card" style="max-width:720px"><p class="eyebrow">CUSTOM & ENTERPRISE</p><h3>Build the right solution.</h3><p>Contact details will be enabled once the FyndEt operating domain and support mailbox are confirmed.</p>';
  if(filter_var($sales,FILTER_VALIDATE_EMAIL))echo '<a class="button button-accent" href="mailto:'.esc($sales).'?subject=FyndEt%20Enterprise%20Enquiry">Email sales ↗</a>';
  else echo '<p class="muted">Sales contact is being configured ahead of public launch.</p>';
  echo '</section></main>';pageEnd();exit;
 }
 if($path==='/pricing'){pageTop('Plans and pricing');echo '<main class="section container"><div class="section-heading centered"><p class="eyebrow">PRICING / FYNDET</p><h1 class="interior-title">Find your <em>depth.</em></h1><p>Plans for curious individuals and professional teams. USD per month; tax may apply.</p></div><div class="price-grid">';card('Free','$0','A first look',['2 checks per month','Domain signals','Self-check mode']);card('Starter','$15','Go further',['25 checks per month','PDF reports','Standard sources']);card('Pro','$25','Dig deeper',['100 checks per month','Extended sources','CSV exports'],true);card('Business','$33','Work together',['250 checks per month','3 proposed seats','Audit controls']);card('Custom','Custom','Made for you',['Negotiated quotas','Dedicated onboarding','Enterprise controls']);echo '</div><p class="pricing-note">Paid billing is not yet active; subscriptions must not be represented as purchased until checkout is configured.</p></main>';pageEnd();exit;}
 if($path==='/how-it-works'){pageTop('How it works');echo '<main class="section container"><div class="section-heading"><p class="eyebrow">THE METHOD</p><h1 class="interior-title">Evidence first.<br><em>Always.</em></h1><p>Our investigation flow is designed around responsible access, transparent sources and interpretable results.</p></div><div class="steps"><article><span>01</span><h2>Create your account</h2><p>Secure your workspace and start with an email you control.</p></article><article><span>02</span><h2>Set the scope</h2><p>Confirm the purpose and authority for the investigation.</p></article><article><span>03</span><h2>Investigate the signals</h2><p>The scheduled worker checks available domain indicators and optional approved sources.</p></article><article><span>04</span><h2>Review the evidence</h2><p>Read time-stamped findings that distinguish observation, missing evidence and source unavailability.</p></article></div><a class="button button-accent" href="/register">Get started ↗</a></main>';pageEnd();exit;}
 if($path==='/legal'){pageTop('Privacy and acceptable use');echo '<main class="section container legal"><p class="eyebrow">LEGAL / POLICY OVERVIEW</p><h1 class="interior-title">Responsible by <em>design.</em></h1><p>FyndEt processes personal information for limited, legitimate email-exposure investigations. The current release permits registered users to check only their own address. We prohibit harassment, stalking, credential attacks, mass profiling and unauthorised account enumeration.</p><p>Investigation records are access-controlled and retained for the configured service period. Public references are not proof of account ownership. We do not collect passwords or publish leaked records. Contact the service operator about privacy requests and corrections.</p><p><strong>Before public production launch:</strong> publish operator-verified contact details, a complete POPIA-compliant privacy notice, terms of service, retention schedule and breach response procedure. This overview is not a substitute for those legal documents.</p></main>';pageEnd();exit;}
 if($path==='/forgot-password'||$path==='/reset-password'){
  pageTop($path==='/forgot-password'?'Password recovery':'Set new password');
  $reset=$path==='/reset-password';
  echo '<main class="auth-layout container"><section class="auth-intro"><p class="eyebrow">ACCOUNT SECURITY</p><h1>Secure access.<br><em>Clear control.</em></h1><p>Your account and investigation history deserve careful protection.</p></section><section class="auth-panel"><p class="eyebrow">FYNDET / ACCOUNT RECOVERY</p><h2>'.($reset?'Choose a new password.':'Recover your account.').'</h2><p class="muted">'.($reset?'Create a password containing at least 12 characters.':'Enter your email and, if it exists, we will send recovery instructions.').'</p><form class="form-stack" method="post" action="'.$path.'"><input type="hidden" name="_csrf" value="'.csrf().'">';
  if($reset)echo '<input type="hidden" name="token" value="'.esc((string)($_GET['token']??'')).'"><label>New password<input type="password" autocomplete="new-password" name="password" minlength="12" maxlength="128" required></label>';
  else echo '<label>Email address<input type="email" autocomplete="email" name="email" required></label>';
  echo '<button type="submit" class="button button-accent full">'.($reset?'Update password':'Send recovery link').' ↗</button></form><p class="auth-switch"><a href="/login">Back to sign in ↗</a></p></section></main>';
  pageEnd();exit;
 }
 if($path==='/register'||$path==='/login'){
  if(user())go('app');$register=$path==='/register';pageTop($register?'Create account':'Sign in');echo '<main class="auth-layout container"><section class="auth-intro"><p class="eyebrow">THE FYNDET WORKSPACE</p><h1>Find the signals.<br><em>Keep the context.</em></h1><p>One private place to explore the digital intelligence associated with your email address.</p><div class="auth-art"><span class="radar-ring"></span><span class="radar-ring second"></span><span class="radar-core">✳</span></div></section><section class="auth-panel"><p class="eyebrow">'.($register?'GET STARTED / FREE':'WELCOME BACK').'</p><h2>'.($register?'Your account starts here.':'Continue your investigation.').'</h2><p class="muted">'.($register?'Start with 2 monthly self-check investigations.':'Access your private workspace.').'</p><form method="post" action="'.$path.'" class="form-stack"><input type="hidden" name="_csrf" value="'.csrf().'">';
  if($register)echo '<label>Full name<input name="name" autocomplete="name" maxlength="120" required placeholder="Your name"></label>';
  echo '<label>Email address<input type="email" name="email" autocomplete="email" required placeholder="you@example.com"></label><label>Password<input type="password" name="password" autocomplete="'.($register?'new-password':'current-password').'" '.($register?'minlength="12"':'').' required placeholder="'.($register?'At least 12 characters':'Your password').'"></label><button class="button button-accent full" type="submit">'.($register?'Create free account':'Sign in').' ↗</button></form><p class="auth-switch">'.($register?'Already have an account? <a href="/login">Sign in</a>':'New to FyndEt? <a href="/register">Create an account</a> · <a href="/forgot-password">Forgot password?</a>').'</p></section></main>';pageEnd();exit;
 }
 if($path==='/app/billing'){
  $u=mustUser();pageTop('Billing');
  echo '<main class="section container billing-page"><div class="section-heading"><p class="eyebrow">BILLING / SUBSCRIPTION</p><h1 class="interior-title">Choose your <em>capacity.</em></h1><p>Current plan: <strong>'.esc(ucfirst($u['plan'])).'</strong>. Your entitlement updates only after the payment provider confirms your subscription.</p></div>';
  if(isset($_GET['success']))echo '<p class="flash">Checkout completed. Your subscription status will update after secure payment confirmation.</p>';
  if(isset($_GET['cancel']))echo '<p class="flash">Checkout cancelled. No plan change was applied.</p>';
  if(empty($u['email_verified_at']))echo '<p class="flash">Verify your registered email address before starting a paid subscription.</p>';
  $available=cfg('PAID_BILLING_ENABLED','0')==='1'&&str_starts_with(cfg('STRIPE_SECRET_KEY'),'sk_')&&str_starts_with(cfg('STRIPE_WEBHOOK_SECRET'),'whsec_');
  if(!$available)echo '<p class="flash">Paid subscriptions are not yet available. The payment gateway is awaiting configuration and verification.</p>';
  echo '<div class="billing-choices">';
  foreach(['starter'=>15,'pro'=>25,'business'=>33] as $plan=>$price){
   echo '<article class="panel"><p class="eyebrow">'.strtoupper($plan).'</p><h2>$'.$price.' <small>/month</small></h2><p class="muted">'.credits($plan).' monthly investigation credits.</p><form action="/billing/checkout" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="plan" value="'.$plan.'"><button class="button button-accent full" type="submit" '.(!$available||empty($u['email_verified_at'])||!stripePrice($plan)?'disabled':'').'>Choose '.ucfirst($plan).' ↗</button></form></article>';
  }
  echo '</div>';if($available){$linked=db()->prepare('SELECT stripe_customer_id FROM users WHERE id=?');$linked->execute([$u['id']]);$customer=$linked->fetchColumn();if(is_string($customer)&&str_starts_with($customer,'cus_'))echo '<section class="panel" style="margin-top:20px"><h2>Manage your subscription</h2><p class="muted">Update your billing details or cancel through the payment provider’s secure customer portal.</p><form method="post" action="/billing/portal"><input type="hidden" name="_csrf" value="'.csrf().'"><button class="button button-outline">Open billing portal ↗</button></form></section>';}echo '<p class="pricing-note">Tax, subscription term and payment details are presented by the hosted checkout. Custom plans require direct approval.</p></main>';
  pageEnd();exit;
 }
 if($path==='/app'||$path==='/app/report'){
  $u=mustUser();pageTop('Workspace',true);$remaining=max(0,credits($u['plan'])-used((int)$u['id']));
  echo '<main class="workspace container"><nav class="workspace-nav"><a href="/app">Investigations</a><a href="/app/billing">Billing</a><a href="/app/team">Team</a><a href="/app/privacy">Privacy</a><a href="/app/security">Security</a><a href="/app/api-keys">API keys</a><a href="/app/monitoring">Monitoring</a></nav>';if(empty($u['email_verified_at']))echo '<div class="verify-banner"><div><strong>Verify your email to activate self-check investigations.</strong><p>We sent a time-limited verification link to your registered address.</p></div><form method="post" action="/resend-verification"><input type="hidden" name="_csrf" value="'.csrf().'"><button class="button button-outline" type="submit">Resend verification ↗</button></form></div>';echo '<div class="workspace-heading"><div><p class="eyebrow">YOUR INTELLIGENCE WORKSPACE</p><h1>Good to see you, <em>'.esc(explode(' ',$u['name'])[0]).'.</em></h1><p class="muted">An evidence-focused view of your personal email footprint.</p></div><span class="plan-label">'.strtoupper(esc($u['plan'])).' ACCOUNT</span></div><div class="workspace-grid"><section class="panel investigate-panel"><p class="eyebrow">NEW INVESTIGATION</p><h2>Start with an address.</h2><p class="muted">The current release is restricted to your own registered address. Results are processed using cPanel Cron.</p><form class="form-stack" method="post" action="/investigate"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Email address<input type="email" name="target" required value="'.esc($u['email']).'"></label><label>Investigation purpose<textarea name="purpose" required minlength="10" maxlength="400" placeholder="I am checking my own email exposure."></textarea></label><label class="check-label"><input type="checkbox" name="authorised" value="1" required><span>I confirm I control this address and authorise this self-check.</span></label><button class="button button-accent full" '.($remaining===0||empty($u['email_verified_at'])?'disabled':'').'>Begin investigation ↗</button></form></section><aside class="panel quota-panel"><p class="eyebrow">CURRENT CYCLE / '.gmdate('M Y').'</p><strong class="quota-number">'.$remaining.'<small> / '.credits($u['plan']).'</small></strong><h3>Investigations remaining</h3><div class="quota-meter"><span style="width:'.round((credits($u['plan'])-$remaining)/max(1,credits($u['plan']))*100).'%"></span></div><p class="muted">Credits reset monthly. Paid upgrades are currently pending billing activation.</p><a class="under-link" href="/app/billing">Manage billing ↗</a></aside></div>';
  if($path==='/app/report'){
   $id=(string)($_GET['id']??'');$q=db()->prepare('SELECT * FROM investigations WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);$report=$q->fetch();
   if(!$report){http_response_code(404);echo '<section class="panel"><h2>Report not found.</h2></section>';}
   else{echo '<section class="panel report-panel">';if(in_array($u['plan'],['starter','pro','business'],true)&&in_array($report['status'],['complete','partial'],true)){echo '<div class="report-actions"><span class="eyebrow">EXPORT OPTIONS</span><div><a class="button button-outline button-small" href="/app/reports/export?format=pdf&amp;id='.esc($id).'">PDF report ↗</a>';if(in_array($u['plan'],['pro','business'],true))echo '<a class="button button-outline button-small" href="/app/reports/export?format=csv&amp;id='.esc($id).'">CSV export ↗</a>';echo '</div></div>';}echo '<div class="report-title"><div><p class="eyebrow">INVESTIGATION / '.esc(substr($id,0,8)).'</p><h2>'.esc($report['target_email']).'</h2></div><span class="status">'.esc(strtoupper($report['status'])).'</span></div>';
   if($report['result_json']){$result=json_decode($report['result_json'],true)?:[];echo '<p class="muted">Collected at '.esc($result['retrieved_at']??'').'. DNS records indicate infrastructure configuration, not the existence of online accounts.</p><div class="result-grid">';
   foreach(($result['records']??[]) as $type=>$values){echo '<article class="result-card"><p class="eyebrow">'.esc($type).'</p>';if($values){foreach($values as $v)echo '<p class="mono">'.esc((string)$v).'</p>';}else echo '<p class="muted">No record observed in this lookup.</p>';echo '</article>';}
   echo '</div>';
   $extended=$result['expanded_discovery']??[];
   if(!empty($extended['sources'])){
     echo '<div class="evidence-section"><p class="eyebrow">SOURCE COVERAGE & PROVENANCE</p><h3>Expanded discovery</h3><p class="muted">Source observations are not proof that the address belongs to an account. Results only cover sources actually checked.</p>';
     foreach($extended['sources'] as $sectionName=>$section){
       $state=(string)($section['state']??'not_checked');
       echo '<article class="evidence-item"><div class="report-title"><h4>'.esc(ucwords(str_replace('_',' ',$sectionName))).'</h4><span class="status">'.esc(strtoupper($state)).'</span></div><p class="muted">'.esc((string)($section['reason']??'')).'</p>';
       if(!empty($section['posture']))echo '<p class="muted">Domain posture: '.esc(json_encode($section['posture'])).'</p>';
       foreach(array_slice($section['items']??[],0,55) as $item){
         echo '<div class="source-observation"><strong>'.esc((string)($item['title']??$item['record_type']??$item['type']??'Observation')).'</strong>';
         echo '<p class="mono">'.esc((string)($item['summary']??$item['value']??'')).'</p>';
         if(!empty($item['source_url'])&&filter_var($item['source_url'],FILTER_VALIDATE_URL))echo '<a class="under-link" href="'.esc($item['source_url']).'" rel="noopener noreferrer" target="_blank">Inspect cited source ↗</a>';
         echo '</div>';
       }
       echo '</article>';
     }
     echo '</div>';
   }
   if(!empty($result['evidence_summary']))echo '<p class="muted">Evidence observations: '.(int)($result['evidence_summary']['observations']??0).'. '.esc((string)($result['evidence_summary']['limitations']??'')).'</p>';
   foreach(['public_references'=>'Public footprint references','breach_exposure'=>'Breach exposure metadata'] as $key=>$heading){
     $data=$result[$key]??['state'=>'not_checked','reason'=>'Not enabled in this release','items'=>[]];
     echo '<div class="evidence-section"><div class="report-title"><h3>'.esc($heading).'</h3><span class="status">'.esc(strtoupper((string)($data['state']??'unknown'))).'</span></div><p class="muted">'.esc((string)($data['reason']??'')).'</p>';
     foreach(($data['items']??[]) as $evidence){
       echo '<article class="evidence-item"><span class="eyebrow">'.esc((string)($evidence['type']??'EVIDENCE')).'</span>';
       echo '<h4>'.esc((string)($evidence['repository']??$evidence['name']??'Source observation')).'</h4>';
       echo '<p>'.esc((string)($evidence['summary']??$evidence['breach_date']??'')).'</p>';
       if(!empty($evidence['data_classes']))echo '<p class="muted">Data categories: '.esc(implode(', ',$evidence['data_classes'])).'</p>';
       if(!empty($evidence['source_url'])&&str_starts_with($evidence['source_url'],'https://github.com/'))echo '<a class="under-link" href="'.esc($evidence['source_url']).'" target="_blank" rel="noopener noreferrer">Inspect public source ↗</a>';
       echo '<p class="muted">'.esc((string)($evidence['caution']??'')).'</p></article>';
     }
     if(empty($data['items']))echo '<p class="muted">No findings shown. Source absence is not proof that an account or breach does not exist.</p>';
     echo '</div>';
   }}
   else echo '<p class="muted">This report has not completed. Refresh after the scheduled worker runs. Failed reports may require administrator review.</p>';
   if($report['error_message'])echo '<p role="alert">'.esc($report['error_message']).'</p>';
   echo '</section>';}
  }
  $q=db()->prepare('SELECT id,target_email,status,created_at FROM investigations WHERE user_id=? ORDER BY created_at DESC LIMIT 20');$q->execute([$u['id']]);$rows=$q->fetchAll();echo '<section class="panel history"><div class="panel-header"><div><p class="eyebrow">YOUR ACTIVITY</p><h2>Recent investigations</h2></div><span>'.count($rows).' shown</span></div><div class="table-scroll"><table><thead><tr><th>SUBJECT</th><th>CREATED</th><th>STATUS</th><th></th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.esc($r['target_email']).'</td><td>'.esc($r['created_at']).' UTC</td><td><span class="status">'.esc($r['status']).'</span></td><td><a href="/app/report?id='.esc($r['id']).'">View ↗</a>';if(!in_array($r['status'],['queued','running'],true))echo '<details class="report-delete"><summary>Delete</summary><form action="/app/investigations/delete" method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="id" value="'.esc($r['id']).'"><label><input type="checkbox" name="confirm_delete" value="1" required> Permanently delete this investigation</label><button type="submit" class="button button-outline button-small">Confirm deletion</button></form></details>';echo '</td></tr>';if(!$rows)echo '<tr><td colspan="4" class="empty-row">No investigations yet. Your findings will appear here.</td></tr>';echo '</tbody></table></div></section></main>';pageEnd();exit;
 }
 http_response_code(404);pageTop('Not found');echo '<main class="section container"><p class="eyebrow">404 / NOT FOUND</p><h1 class="interior-title">Nothing here.<br><em>Keep exploring.</em></h1><a class="button button-accent" href="/">Return home ↗</a></main>';pageEnd();
}catch(DomainException $e){http_response_code(422);pageTop('Please review');echo '<main class="section container error-page"><p class="eyebrow">ACTION NOT COMPLETED</p><h1 class="interior-title">Something needs <em>attention.</em></h1><p>'.esc($e->getMessage()).'</p><a class="button button-outline" href="/login">Try again ←</a><a class="button button-accent" href="/">Home ↗</a></main>';pageEnd();}
catch(Throwable $e){error_log('FyndEt: '.$e->getMessage());http_response_code(503);echo '<!doctype html><title>Service temporarily unavailable — FyndEt</title><main style="max-width:650px;margin:12vh auto;font:18px system-ui;padding:25px"><h1>Service temporarily unavailable</h1><p>FyndEt could not complete this request. If this is a new installation, configure the database and run the migration. Please try again later.</p></main>';}
