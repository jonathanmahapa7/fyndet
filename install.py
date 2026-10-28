#!/usr/bin/env python3
"""FyndEt R0 installer: bathokwe-only, backup-first, anchor-checked, fail closed."""
from pathlib import Path
import os,sys,subprocess,hashlib,tarfile,shutil,datetime,stat
APP=Path('/home/bathokwe/fyndet-app')
PAY=Path(__file__).resolve().parent
PHP='/opt/cpanel/ea-php83/root/usr/bin/php'
ADMIN=os.environ.get('FYNDET_R0_ADMIN_EMAIL','').strip().lower()
LIVE=Path(os.environ.get('FYNDET_R0_LIVE_ENTRY','/nonexistent/fyndet-r0-live-entry.php'))

def run(args, **kw):
 print('RUN',' '.join(map(str,args)),flush=True)
 return subprocess.run(list(map(str,args)),check=True,**kw)
def anchor(text,old,new,label):
 count=text.count(old)
 if count!=1:raise RuntimeError(f'PREFLIGHT FAIL {label}: exact single-line anchor found {count} times')
 if '\n' in old:raise RuntimeError(f'Invalid multiline anchor: {label}')
 return text.replace(old,new,1)

def prepare():
 edits={}
 def load(rel):
  p=APP/rel
  if not p.is_file():raise RuntimeError(f'Missing installed file {rel}')
  return p.read_text(encoding='utf-8')
 s=load('app/discovery_pipeline.php')
 s=anchor(s,"require_once __DIR__.'/discovery_sources.php';","require_once __DIR__.'/discovery_sources.php';\nrequire_once __DIR__.'/discovery_r0_base.php';",'pipeline base import')
 s=anchor(s,'$result=fyndetBuildFootprint($email,$env,$authorised);','$result=fyndetR0BaseFootprint($email,$authorised);','disable uncleared automatic legacy sources')
 s=anchor(s,"'openpgp'=>'technical_associations','metasearch'=>'web_presence'","'openpgp'=>'technical_associations'",'metasearch category')
 s=anchor(s,"'openpgp'=>fn()=>fyndetDOpenPgp($email,$env),'metasearch'=>fn()=>fyndetDMeta($email,$env)","'openpgp'=>fn()=>fyndetDOpenPgp($email,$env)",'metasearch provider')
 edits['app/discovery_pipeline.php']=s
 s=load('app/discovery_sources.php')
 s=anchor(s,"function fyndetDOpenPgp(string $email,array $env):array {","function fyndetDOpenPgp(string $email,array $env):array {return ['state'=>'not_configured','items'=>[],'reason'=>'R0_openpgp_disabled'];",'disable OpenPGP regardless of environment')
 key='function fyndetDMeta('
 if s.count(key)!=1:raise RuntimeError(f'PREFLIGHT FAIL metasearch source function: {s.count(key)}')
 before,tail=s.split(key,1)
 if 'function ' in tail:raise RuntimeError('Metasearch function not final; refuse removal')
 edits['app/discovery_sources.php']=before.rstrip()+'\n'
 s=load('app/discovery_verifier.php')
 s=anchor(s,"strtolower($p['scheme']??'')!=='https'","!in_array(strtolower($p['scheme']??''),['http','https'],true)",'URL allowed protocols')
 s=anchor(s,"isset($p['port']) || strlen($url)>1800","(isset($p['port']) && !in_array((int)$p['port'],[80,443],true)) || (isset($p['port']) && (int)$p['port']!==(strtolower($p['scheme'])==='https'?443:80)) || strlen($url)>1800",'safe URL port')
 s=anchor(s,'[$host]=$p;$ip=fyndetVResolve($host);',"[$host]=$p;$scheme=strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:'https'));$port=$scheme==='https'?443:80;$ip=fyndetVResolve($host);",'pinned port')
 s=anchor(s,'CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS','CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS','protocol allowlist')
 s=anchor(s,'CURLOPT_RESOLVE=>["$host:443:$ip"]','CURLOPT_RESOLVE=>["$host:$port:$ip"]','DNS pinning')
 s=anchor(s,"fyndetVFetch('https://'.$host.'/robots.txt',90000)","fyndetVFetch(strtolower((string)(parse_url($url,PHP_URL_SCHEME)?:'https')).'://'.$host.'/robots.txt',90000)",'robots same scheme')
 edits['app/discovery_verifier.php']=s
 s=load('bin/discovery_canary.php')
 s=anchor(s,"$env['FYNDET_METASEARCH_ENABLED']='0';","$asOwner=in_array('--as-owner',$argv,true) && strtolower($email)===strtolower((string)(getenv('FYNDET_R0_ADMIN_EMAIL')?:''));\necho 'AUTHORISATION: '.($asOwner?'verified self-check canary':'public only').PHP_EOL;",'canary enable override')
 s=anchor(s,'$r=fyndetDiscoveryRun($email,$env,false);','$r=fyndetDiscoveryRun($email,$env,$asOwner);','canary owner parity')
 edits['bin/discovery_canary.php']=s
 def public_patch(s,label):
  s=anchor(s,"require_once __DIR__.'/mount.php';","require_once __DIR__.'/mount.php';\nheader('Referrer-Policy: no-referrer');",label+' referrer policy')
  route="if($path==='/investigate'&&$_SERVER['REQUEST_METHOD']==='POST'){"
  s=anchor(s,route,"if($path==='/app/report/urls'&&$_SERVER['REQUEST_METHOD']==='POST'){require_once $root.'/app/r0_ui.php';fyndetR0HandlePost($root,$env);}\n  "+route,label+' authenticated URL route')
  s=anchor(s,"if($report['result_json']){","require_once $root.'/app/r0_ui.php';fyndetR0ReportWidget($root,$u,$report,csrf());\n    if(false && $report['result_json']){",label+' report R0 UI / disable legacy display')
  s=anchor(s,"else echo '<p class=\"muted\">This report has not completed. Refresh after the scheduled worker runs. Failed reports may require administrator review.</p>';","else echo '<p class=\"muted\">R0 source evidence is shown above. Legacy domain-only analysis is retired from this view.</p>';",label+' suppress misleading pending state')
  return s
 edits['public/index.php']=public_patch(load('public/index.php'),'app public')
 if not LIVE.is_file():raise RuntimeError('Live cPanel entry point missing: '+str(LIVE))
 live_patch=public_patch(LIVE.read_text(encoding='utf-8'),'live public')
 s=load('bin/process-investigations.php')
 if s.count('fyndetDiscoveryRun(')!=1:raise RuntimeError('Worker does not call shared function exactly once')
 if s.count('fyndetBuildFootprint(')!=0:raise RuntimeError('Worker still references unshared engine')
 # Worker left untouched; the shared function it calls is the R0 orchestrator.
 s=load('README.md');s=anchor(s,'# FyndEt','# FyndEt\n\n'+(PAY/'README-R0.md').read_text().strip()+'\n','R0 README header')
 edits['README.md']=s
 s=load('docs/DISCOVERY_COVERAGE.md')
 if s.count('# Discovery Source Coverage and Activation')!=1:raise RuntimeError('DISCOVERY_COVERAGE header unexpected')
 edits['docs/DISCOVERY_COVERAGE.md']=(PAY/'docs/DISCOVERY_COVERAGE.md').read_text()
 if not (APP/'bin/discovery_metasearch_spike.php').exists():raise RuntimeError('Expected SearXNG spike file missing; fail closed')
 new={}
 for path in PAY.rglob('*.php'):
  rel=path.relative_to(PAY).as_posix()
  if rel not in edits and not (APP/rel).exists():new[rel]=path.read_text()
  elif rel not in edits:raise RuntimeError('New file unexpectedly exists: '+rel)
 for rel in ['README-R0.md','bin/r0-rollback.py']:
  new[rel]=(PAY/rel).read_text()
 return edits,new,live_patch

def back_up():
 ts=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 dest=APP/'var'/('r0-backup-'+ts)
 dest.mkdir(mode=0o700,parents=True,exist_ok=False)
 shutil.copy2(LIVE,dest/'live-index-before.php')
 (dest/'live-entry-path.txt').write_text(str(LIVE))
 with tarfile.open(dest/'application-before.tar.gz','w:gz') as tar:
  for rel in ['app','bin','tests','public','README.md','docs/DISCOVERY_COVERAGE.md']:
   if (APP/rel).exists():tar.add(APP/rel,arcname=rel,recursive=True)
 with (dest/'sha256-before.tsv').open('w') as out:
  out.write('LIVE '+str(LIVE)+'\t'+hashlib.sha256(LIVE.read_bytes()).hexdigest()+'\n')
  for folder in ('app','bin','tests','public'):
   for f in sorted((APP/folder).rglob('*.php')):
    out.write(f'{f.relative_to(APP)}\t{hashlib.sha256(f.read_bytes()).hexdigest()}\n')
 for ref,cmd in [('git-head',['git','-C',str(APP),'rev-parse','HEAD']),('git-status',['git','-C',str(APP),'status','--porcelain=v1'])]:
  r=subprocess.run(cmd,text=True,capture_output=True)
  (dest/(ref+'.txt')).write_text(r.stdout+('\nERROR: '+r.stderr if r.returncode else ''))
 cron=subprocess.run(['crontab','-l'],text=True,capture_output=True)
 (dest/'crontab-before.txt').write_text(cron.stdout if cron.returncode==0 else '')
 print('BACKUP:',dest,flush=True)
 return dest

def restore(back,installed_new):
 print('FAIL — rolling back files from',back,flush=True)
 for rel in installed_new:
  p=APP/rel
  if p.is_file():p.unlink()
 if (back/'live-index-before.php').is_file():shutil.copy2(back/'live-index-before.php',LIVE)
 with tarfile.open(back/'application-before.tar.gz') as tar:
  for entry in tar.getmembers():
   target=(APP/entry.name).resolve()
   if not str(target).startswith(str(APP.resolve())+'/'):raise RuntimeError('Unsafe archive member')
  tar.extractall(APP)
 try:run(['crontab',str(back/'crontab-before.txt')])
 except Exception as e:print('WARN: cron restoration:',e)

def migrate():
 php='''<?php
$root=$argv[1];$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if(!$line||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \\t\\r\\n\\\"'");}
$db=new PDO('mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??3306).';dbname='.$env['DB_NAME'].';charset=utf8mb4',$env['DB_USER'],$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require $root.'/app/r0_storage.php';fyndetR0Migrate($db);echo "PASS: Additive R0 tables\n";
'''
 path=APP/'var'/'r0-migrate-tmp.php';path.write_text(php)
 try:run([PHP,str(path),str(APP)])
 finally:path.unlink(missing_ok=True)

def main():
 if not ADMIN or '@' not in ADMIN:raise RuntimeError('FYNDET_R0_ADMIN_EMAIL required at runtime')
 if os.getuid()==0:raise RuntimeError('Run as bathokwe, never root')
 if not APP.is_dir() or not os.access(APP,os.W_OK):raise RuntimeError('App directory not writable')
 backup=back_up(); installed=[]
 try:
  edits,new,live_patch=prepare()  # all anchors (including live cPanel) before any application change
  for rel,source in {**edits,**new}.items():
   stage=PAY/rel
   stage.parent.mkdir(parents=True,exist_ok=True)
   stage.write_text(source,encoding='utf-8')
  live_stage=PAY/'live-index.php';live_stage.write_text(live_patch,encoding='utf-8')
  for rel in list(edits)+list(new):
   if rel.endswith('.php'):run([PHP,'-l',str(PAY/rel)])
  run([PHP,'-l',str(live_stage)])
  # Test against staged new verifier, not installed code.
  run([PHP,str(PAY/'tests/discovery-r0-offline.php')])
  if (APP/'var/fyndet-r0-flags.ini').exists():raise RuntimeError('Existing flags file: refuse replacement before changes')
  # Apply only after complete anchor preflight and staged validation.
  for rel in list(edits)+list(new):
   p=APP/rel;p.parent.mkdir(parents=True,exist_ok=True)
   if rel in new:installed.append(rel)
   else:
    if p.is_file():p.unlink()  # replaced from backup on failure
   shutil.copy2(PAY/rel,p)
  shutil.copy2(live_stage,LIVE)
  removed=APP/'bin/discovery_metasearch_spike.php';removed.unlink()
  for rel in list(edits)+list(new):
   if rel.endswith('.php'):run([PHP,'-l',str(APP/rel)])
  run([PHP,'-l',str(LIVE)])
  run([PHP,str(APP/'tests/discovery-r0-offline.php')])
  migrate()  # additive DDL, tables intentionally retained on rollback
  flags=APP/'var/fyndet-r0-flags.ini'
  if flags.exists():raise RuntimeError('R0 flags file already exists; refuse overwrite')
  flags.write_text('FYNDET_URL_INTAKE_ENABLED=1\nFYNDET_URL_INTAKE_ADMIN_ONLY=1\nFYNDET_R0_ADMIN_SHA256='+hashlib.sha256(ADMIN.encode()).hexdigest()+'\n')
  flags.chmod(0o600);installed.append('var/fyndet-r0-flags.ini')
  cron=subprocess.run(['crontab','-l'],text=True,capture_output=True)
  old=cron.stdout if cron.returncode==0 else ''
  signature='bin/r0-intake-worker.php'
  if signature not in old:
   line=f'* * * * * {PHP} {APP}/{signature} >> {APP}/var/r0-intake.log 2>&1'
   run(['crontab','-'],input=old.rstrip('\n')+'\n'+line+'\n',text=True)
  print('===== ADMIN CANARY — --as-owner =====',flush=True)
  run([PHP,str(APP/'bin/discovery_canary.php'),ADMIN,'--as-owner'])
  print('===== GROUND-TRUTH FIXTURE =====',flush=True)
  run([PHP,str(APP/'bin/fixture-check.php')])
  for source,reason in [('Hacker News Algolia','commercial automated-use permission not cleared'),('Stack Exchange','commercial processing and retention not cleared'),('Bluesky','commercial automated-use permission not cleared'),('Crossref','R0 adapter disabled pending policy review'),('Wikimedia','attribution and endpoint compliance not activated'),('Wikidata','endpoint permission not cleared'),('Gravatar','limited to verified self-check; not automatically enabled'),('Libravatar','automated-use permission not cleared'),('OpenPGP','disabled by R0 policy'),('mailing-list archives','automated-use permission not cleared')]:print('SOURCE: '+source+' disabled — '+reason)
  print('PASS: R0 release installed; admin-only URL intake enabled.')
  print('BACKUP:',backup)
  print('ROLLBACK: runuser -u bathokwe -- python3 '+str(APP/'bin/r0-rollback.py')+' '+str(backup))
 except Exception as e:
  print('FAIL:',str(e),file=sys.stderr)
  restore(backup,installed)
  print('BACKUP:',backup,flush=True)
  sys.exit(1)
if __name__=='__main__':main()
