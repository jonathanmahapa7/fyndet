<?php
declare(strict_types=1);
/** R0: no automatic external search or unlicensed provider calls. */
function fyndetR0BaseFootprint(string $email,bool $owner):array {
 $sections=[];
 foreach(fyndetFootprintHeadings() as $key=>$label){
  $state=$key==='security_exposure'?'not_checked':($key==='technical_associations'?'not_configured':'partial_coverage');
  $sections[$key]=['label'=>$label,'state'=>$state,'items'=>[],'sources_checked'=>0,'sources_unavailable'=>0,
   'reason'=>$key==='security_exposure'?'Sensitive exposure checks not authorised in R0':($key==='technical_associations'?'No approved technical source configured':'No broad indexed search or enabled automated provider in R0')];
 }
 return ['footprint'=>$sections,'retrieved_at'=>gmdate('c'),'methodology'=>'R0 manual-search links and source-verified submitted URLs only'];
}
