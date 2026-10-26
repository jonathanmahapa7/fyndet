<?php
declare(strict_types=1);
/** USD is the commercial price; ZAR is informational until a gateway locks a quote. */
function fyndetUsdCents(string $plan):int {
 $defaults=['starter'=>1500,'pro'=>2500,'business'=>3300];
 if(!isset($defaults[$plan]))return 0;
 $raw=cfg('PRICE_'.strtoupper($plan).'_USD',number_format($defaults[$plan]/100,2,'.',''));
 if(!preg_match('/^[1-9][0-9]{0,4}(?:\.[0-9]{2})?$/D',$raw))throw new DomainException('Invalid USD subscription price configuration.');
 return (int)round(((float)$raw)*100);
}
function fyndetFxRate():?array {
 if(cfg('FX_USD_ZAR_ENABLED','1')!=='1')return null;
 $dir=dirname(__DIR__).'/var';$file=$dir.'/usd-zar-rate.json';
 $decode=static function(string $json):?array {
  $row=json_decode($json,true);
  if(!is_array($row)||($row['base']??'')!=='USD'||($row['quote']??'')!=='ZAR')return null;
  if(!is_numeric($row['rate']??null)||$row['rate']<5||$row['rate']>100)return null;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($row['date']??'')))return null;
  if(!is_numeric($row['fetched_at']??null)||$row['fetched_at']>time()+300)return null;
  return $row;
 };
 $cached=is_file($file)?$decode((string)@file_get_contents($file)):null;
 if($cached && time()-(int)$cached['fetched_at']<21600)return $cached;
 if(!function_exists('curl_init'))return $cached && time()-(int)$cached['fetched_at']<86400?$cached:null;
 $h=curl_init('https://api.frankfurter.dev/v2/rate/USD/ZAR');
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $body=curl_exec($h);$http=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 $row=$http===200&&is_string($body)?json_decode($body,true):null;
 if(is_array($row)){
  $row['base']=strtoupper((string)($row['base']??''));
  $row['quote']=strtoupper((string)($row['quote']??''));
  $row['fetched_at']=time();
  $valid=$decode((string)json_encode($row));
  if($valid){
   if(is_dir($dir)&&is_writable($dir)){
    $temp=@tempnam($dir,'.fx-');
    if($temp!==false){
     @chmod($temp,0600);
     if(@file_put_contents($temp,json_encode($valid),LOCK_EX)!==false)@rename($temp,$file);
     else @unlink($temp);
    }
   }
   return $valid;
  }
 }
 return $cached && time()-(int)$cached['fetched_at']<86400?$cached:null;
}
function fyndetIndicativeZar(string $plan,?array $rate):?string {
 if(!$rate)return null;
 return number_format((int)round(fyndetUsdCents($plan)*(float)$rate['rate'])/100,2,'.',',');
}
