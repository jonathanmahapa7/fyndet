<?php
declare(strict_types=1);
/**
 * FyndEt cPanel migration runner.
 * Usage: php bin/migrate-cpanel.php --check
 *        php bin/migrate-cpanel.php --apply
 * Database scope is exclusively the configured FyndEt database.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$mode=$argv[1]??'--check';if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Usage: --check or --apply\n");exit(2);}
$root=dirname(__DIR__);$env=[];
if(!is_file($root.'/.env')){fwrite(STDERR,"Private .env missing\n");exit(1);}
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$env[trim($k)]=trim($v," \t\n\r\0\x0B\"'");}
$dsn='mysql:host='.($env['DB_HOST']??'localhost').';port='.($env['DB_PORT']??'3306').';dbname='.($env['DB_NAME']??'').';charset=utf8mb4';
$db=new PDO($dsn,$env['DB_USER']??'',$env['DB_PASS']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$migrations=['schema.sql','002_identity.sql','003_password_reset.sql','004_organisations_privacy.sql','005_operations.sql'];
$tracked=false;
try{$tracked=(bool)$db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();}catch(Throwable $e){}
$applied=[];
if($tracked){foreach($db->query('SELECT name,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row)$applied[$row['name']]=$row['checksum'];}
if($mode==='--apply'){
 if(!is_dir($root.'/var'))mkdir($root.'/var',0700,true);
 $handle=fopen($root.'/var/migrate.lock','c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Migration already running\n");exit(1);}
 $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(150) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
foreach($migrations as $file){
 $sql=file_get_contents($root.'/database/'.$file);if($sql===false)throw new RuntimeException('Missing migration '.$file);
 $checksum=hash('sha256',$sql);
 if(isset($applied[$file])){
  if(!hash_equals($applied[$file],$checksum))throw new RuntimeException('Previously applied migration changed: '.$file);
  echo "APPLIED $file\n";continue;
 }
 if($mode==='--check'){echo "PENDING $file\n";continue;}
 echo "RUNNING $file\n";
 // Each SQL migration is plain DDL with no stored procedures; MariaDB MySQL DDL commits implicitly.
 // Test and back up BEFORE running --apply; only track a migration after all statements succeed.
 foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
 $q=$db->prepare('INSERT INTO schema_migrations(name,checksum) VALUES(?,?)');$q->execute([$file,$checksum]);echo "SUCCESS $file\n";
}
if($mode==='--apply'){flock($handle,LOCK_UN);fclose($handle);}
echo "FyndEt migrations $mode completed\n";
