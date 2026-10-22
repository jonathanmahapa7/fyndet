<?php
declare(strict_types=1);
/**
 * Self-service data portability and investigation deletion.
 * Session access, CSRF and tenant ownership enforced on every action.
 */
if($path==='/app/privacy/export'){
 if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
 $u=mustUser();
 $query=db()->prepare('SELECT id,target_email,purpose,status,result_json,created_at,finished_at FROM investigations WHERE user_id=? ORDER BY created_at DESC');
 $query->execute([$u['id']]);$history=[];
 foreach($query->fetchAll() as $row){$row['result']=is_string($row['result_json'])?json_decode($row['result_json'],true):null;unset($row['result_json']);$history[]=$row;}
 $query=db()->prepare('SELECT request_type,details,status,created_at,closed_at,resolution_note FROM privacy_requests WHERE user_id=? ORDER BY created_at DESC');
 $query->execute([$u['id']]);$privacy=$query->fetchAll();
 $payload=[
  'exported_at'=>gmdate('c'),
  'account'=>['email'=>$u['email'],'name'=>$u['name'],'effective_plan'=>$u['plan']],
  'investigations'=>$history,'privacy_requests'=>$privacy,
  'notes'=>['This is your account data export. It does not include passwords, payment-card records, secret API token values or other team members\' private reports.']
 ];
 auditEvent((int)$u['id'],'privacy.data_exported');
 header('Content-Type: application/json; charset=UTF-8');
 header('Content-Disposition: attachment; filename="fyndet-account-export.json"');
 header('X-Content-Type-Options: nosniff');
 header('X-Robots-Tag: noindex, nofollow');
 echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if($path==='/app/investigations/delete'){
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 verifyCsrf();$u=mustUser();$id=(string)($_POST['id']??'');
 if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new DomainException('Invalid investigation identifier.');
 $db=db();$db->beginTransaction();
 $q=$db->prepare('SELECT status FROM investigations WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$id,$u['id']]);$found=$q->fetch();
 if(!$found){$db->rollBack();throw new DomainException('Investigation not found.');}
 if(in_array($found['status'],['queued','running'],true)){$db->rollBack();throw new DomainException('An active investigation cannot be deleted until processing completes.');}
 $q=$db->prepare('DELETE FROM investigations WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);$db->commit();
 auditEvent((int)$u['id'],'investigation.deleted',substr($id,0,16));
 flash('Investigation record deleted. Your billing-period usage credit remains counted.');go('app');
}
