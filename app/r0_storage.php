<?php
declare(strict_types=1);
require_once __DIR__.'/discovery_search_links.php';
require_once __DIR__.'/discovery_url_intake.php';
function fyndetR0Flags(string $root):array {
 $file=$root.'/var/fyndet-r0-flags.ini';return is_file($file)?(parse_ini_file($file,false,INI_SCANNER_RAW)?:[]):[];
}
function fyndetR0Enabled(string $root,array $user):bool {
 $flags=fyndetR0Flags($root);
 if(($flags['FYNDET_URL_INTAKE_ENABLED']??'0')!=='1')return false;
 if(($flags['FYNDET_URL_INTAKE_ADMIN_ONLY']??'1')==='1')return hash_equals((string)($flags['FYNDET_R0_ADMIN_SHA256']??''),hash('sha256',strtolower((string)($user['email']??'')))) && !empty($user['email_verified_at']);
 return !empty($user['email_verified_at']);
}
function fyndetR0Migrate(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS fyndet_url_submissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 investigation_id VARCHAR(64) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,target_email VARCHAR(254) NOT NULL,
 source_url VARCHAR(1800) NOT NULL,canonical_url VARCHAR(1800) NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'queued',disposition VARCHAR(64) DEFAULT NULL,
 http_status SMALLINT DEFAULT NULL,matched_snippet TEXT DEFAULT NULL,content_hash CHAR(64) DEFAULT NULL,
 reason VARCHAR(160) DEFAULT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at DATETIME DEFAULT NULL,KEY fyndet_url_job (investigation_id),KEY fyndet_url_queue (status,created_at),KEY fyndet_url_user (user_id,created_at)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS fyndet_url_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,investigation_id VARCHAR(64) NOT NULL,
 submission_id BIGINT UNSIGNED DEFAULT NULL,event VARCHAR(64) NOT NULL,detail VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY fyndet_audit_user (user_id,created_at)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $db->exec("CREATE TABLE IF NOT EXISTS fyndet_url_suppression (
 email_sha256 CHAR(64) NOT NULL PRIMARY KEY,reason VARCHAR(160) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function fyndetR0Audit(PDO $db,int $uid,string $job,?int $id,string $event,string $detail):void {
 $q=$db->prepare('INSERT INTO fyndet_url_audit(user_id,investigation_id,submission_id,event,detail) VALUES(?,?,?,?,?)');
 $q->execute([$uid,$job,$id,$event,substr($detail,0,255)]);
}
