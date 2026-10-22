<?php
declare(strict_types=1);
/** Tenant-isolated export. Never serve a report without session and entitlement checks. */
if($path!=='/app/reports/export')return;
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
$u=mustUser();$format=(string)($_GET['format']??'pdf');$id=(string)($_GET['id']??'');
if(!preg_match('/^[0-9a-f]{32}$/',$id)||!in_array($format,['pdf','csv'],true))throw new DomainException('Invalid report or export format.');
$allowed=match($format){
 'pdf'=>in_array($u['plan'],['starter','pro','business'],true),
 'csv'=>in_array($u['plan'],['pro','business'],true),
 default=>false
};
if(!$allowed)throw new DomainException('Your plan does not include this report export format.');
$q=db()->prepare("SELECT id,target_email,status,result_json,created_at FROM investigations WHERE id=? AND user_id=? AND status IN ('complete','partial')");
$q->execute([$id,$u['id']]);$report=$q->fetch();
if(!$report||!$report['result_json'])throw new DomainException('This report is not ready or is no longer available.');
$data=json_decode($report['result_json'],true);
if(!is_array($data))throw new RuntimeException('Report data is unavailable.');
$rows=[['Section','Source or record','Value','Evidence status']];
$rows[]=['Investigation','Subject email',$report['target_email'],'verified self-check'];
$rows[]=['Investigation','Created at', (string)$report['created_at'],'completed'];
$rows[]=['Investigation','Collection time',(string)($data['retrieved_at']??''),'observed'];
foreach(($data['records']??[]) as $type=>$values){
 if(!is_array($values)||!$values){$rows[]=['Domain / '.$type,'Public DNS','No record observed','not_found'];continue;}
 foreach($values as $val)$rows[]=['Domain / '.$type,'Public DNS',mb_substr((string)$val,0,450),'observed'];
}
foreach(['public_references'=>'Public reference','breach_exposure'=>'Breach exposure'] as $key=>$section){
 $payload=$data[$key]??[];
 if(empty($payload['items']))$rows[]=[$section,'Not available',(string)($payload['reason']??'No data checked'),(string)($payload['state']??'not_checked')];
 foreach(($payload['items']??[]) as $item){
  $ref=(string)($item['source_url']??$item['source']??'');
  $val=(string)($item['repository']??$item['name']??'');
  if(!empty($item['breach_date']))$val.=' · '.$item['breach_date'];
  $rows[]=[$section,mb_substr($ref,0,320),mb_substr($val,0,320),(string)($item['state']??'observed')];
 }
}
header('X-Robots-Tag: noindex, nofollow');
if($format==='csv'){
 header('Content-Type: text/csv; charset=UTF-8');
 header('Content-Disposition: attachment; filename="fyndet-'.substr($id,0,12).'.csv"');
 $out=fopen('php://output','wb');
 fwrite($out,"\xEF\xBB\xBF");
 foreach($rows as $row){
  // Prevent formula injection in downloaded spreadsheets.
  $safe=array_map(static function($v):string{$v=(string)$v;return preg_match('/^[\s]*[=+@\-]/u',$v)?"'".$v:$v;},$row);
  fputcsv($out,$safe);
 }
 fclose($out);exit;
}
$autoload=dirname(__DIR__).'/vendor/autoload.php';
if(!is_file($autoload))throw new DomainException('PDF export is not installed. The operator must run Composer installation.');
require_once $autoload;
if(!class_exists(\Dompdf\Dompdf::class))throw new RuntimeException('PDF renderer not installed.');
$html='<!doctype html><html><head><meta charset="UTF-8"><style>body{font:12px DejaVu Sans,sans-serif;color:#16292c}h1{font-size:27px;margin:5px 0}h2{font-size:17px;margin:22px 0 10px}.top{border-bottom:3px solid #456e69;padding-bottom:16px;margin-bottom:20px}.label{font-size:9px;color:#376f66;letter-spacing:2px}.muted{color:#647477;font-size:10px}table{width:100%;border-collapse:collapse;margin-top:15px;table-layout:fixed}td,th{border-bottom:1px solid #d3dddc;padding:8px 5px;vertical-align:top;text-align:left;word-wrap:break-word}th{background:#edf3ee;font-size:10px}td{font-size:9px}.footer{margin-top:25px;border-top:1px solid #d0d9d7;padding-top:12px;color:#647477}</style></head><body><div class="top"><div class="label">FYNDET / INVESTIGATION REPORT</div><h1>'.esc($report['target_email']).'</h1><p class="muted">Report '.esc(substr($id,0,12)).' · Created '.esc($report['created_at']).' UTC · Generated '.gmdate('Y-m-d H:i').' UTC</p></div><p>This self-check report records the sources queried and their observations. An absent observation is not proof an account or breach does not exist. Sources may be unavailable, incomplete or time-sensitive.</p><h2>Recorded evidence</h2><table><thead><tr>';
foreach($rows[0] as $cell)$html.='<th>'.esc($cell).'</th>';
$html.='</tr></thead><tbody>';foreach(array_slice($rows,1) as $row){$html.='<tr>';foreach($row as $cell)$html.='<td>'.esc((string)$cell).'</td>';$html.='</tr>';}
$html.='</tbody></table><div class="footer">FyndEt · Evidence-led intelligence · Confidential personal self-check · Findings are not proof of identity or wrongdoing.</div></body></html>';
$options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('defaultFont','DejaVu Sans');$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html);$pdf->setPaper('A4');$pdf->render();
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="fyndet-'.substr($id,0,12).'.pdf"');echo $pdf->output();exit;
