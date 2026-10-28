<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/discovery_url_intake.php';
require_once dirname(__DIR__).'/app/discovery_search_links.php';
$email='person@example.test';
$tests=0;
function checkR0(bool $pass,string $label):void{global $tests;if(!$pass)throw new RuntimeException($label);$tests++;}
checkR0(fyndetR0Match($email,'Published person@example.test')!==null,'exact email');
checkR0(fyndetR0Match($email,'xperson@example.test')===null,'prefix boundary');
checkR0(fyndetR0Match($email,'person@example.test.au')===null,'suffix boundary');
checkR0(fyndetR0Match($email,'person [at] example.test')!==null,'obfuscation');
checkR0(fyndetR0Canonical('http://example.org/a#part')==='http://example.org/a','http canonical');
checkR0(fyndetR0Canonical('http://127.0.0.1/a')===null,'private destination blocked');
$fetch=static fn($url)=>['state'=>'fetched','body'=>'Person: '.$email,'http_status'=>200];
checkR0((fyndetR0Verify($email,'https://example.org/post',$fetch)['state']??'')==='content_verified','verified synthetic response');
checkR0((fyndetR0Verify($email,'https://example.org/?q='.$email,$fetch)['state']??'')==='user_reported','query echo denied');
$fetchNo=static fn($url)=>['state'=>'fetched','body'=>'No email here','http_status'=>200];
checkR0((fyndetR0Verify($email,'https://example.org/a',$fetchNo)['state']??'')==='user_reported','negative synthetic');
checkR0(count(fyndetR0SearchLinks($email))===15,'manual search links');
echo "PASS: $tests offline R0 contract tests\n";
