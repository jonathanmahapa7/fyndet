<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=fyndet_test;charset=utf8mb4','root','testpass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['database/schema.sql','database/002_identity.sql','database/003_password_reset.sql','database/004_organisations_privacy.sql','database/005_operations.sql'] as $file){
 $sql=file_get_contents(dirname(__DIR__).'/'.$file);
 foreach(explode(';',$sql) as $part)if(trim($part)!=='')$pdo->exec($part);
 echo "Applied $file\n";
}
