<?php
declare(strict_types=1);
function fyndetR0SearchLinks(string $email):array {
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return [];
 [$local,$domain]=explode('@',strtolower($email),2);
 $variants=['quoted'=>'"'.$email.'"','mailto'=>'"mailto:'.$email.'"','obfuscated'=>'"'.$local.' [at] '.$domain.'"'];
 $engines=['Google'=>'https://www.google.com/search?q=', 'Bing'=>'https://www.bing.com/search?q=',
 'DuckDuckGo'=>'https://duckduckgo.com/?q=', 'Brave'=>'https://search.brave.com/search?q=', 'Yandex'=>'https://yandex.com/search/?text='];
 $out=[];foreach($engines as $name=>$prefix)foreach($variants as $type=>$query)$out[]=['engine'=>$name,'type'=>$type,'url'=>$prefix.rawurlencode($query)];
 return $out;
}
