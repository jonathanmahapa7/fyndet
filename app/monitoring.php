<?php
declare(strict_types=1);
/** Verified own-address monitoring; Pro/Business only and credits metered. */
if($path==='/app/monitoring/save'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$u=mustUser();
 if(empty($u['email_verified_at'])||!in_array($u['plan'],['pro','business'],true))throw new DomainException('Verified Pro or Business subscription required for recurring monitoring.');
 $enabled=!empty($_POST['enabled'])?1:0;
 $interval=(int)($_POST['interval_days']??7);
 if(!in_array($interval,[7,14,30],true))throw new DomainException('Choose a valid recurring interval.');
 $q=db()->prepare('INSERT INTO monitoring_profiles(user_id,enabled,interval_days) VALUES(?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),interval_days=VALUES(interval_days)');
 $q->execute([$u['id'],$enabled,$interval]);
 auditEvent((int)$u['id'],'monitoring.preference_changed',(string)$interval);
 flash($enabled?'Recurring own-address monitoring enabled. Your plan credits will be used for each scheduled scan.':'Recurring monitoring disabled.');go('app/monitoring');
}
if($path==='/app/monitoring'){
 $u=mustUser();pageTop('Footprint monitoring');
 $q=db()->prepare('SELECT enabled,interval_days,last_queued_at,last_changed_at FROM monitoring_profiles WHERE user_id=?');$q->execute([$u['id']]);$profile=$q->fetch();
 echo '<main class="section container"><div class="section-heading"><p class="eyebrow">CONTINUOUS INTELLIGENCE</p><h1 class="interior-title">Watch your <em>footprint.</em></h1><p>Opt-in recurring self-checks can identify material changes in your own indexed digital evidence. Each scan consumes an investigation credit and uses the sources currently configured.</p></div><div class="workspace-grid"><section class="panel"><h2>Monitoring preferences</h2><p class="muted">Enabled: '.(!empty($profile['enabled'])?'Yes':'No').'</p><form method="post" class="form-stack" action="/app/monitoring/save"><input type="hidden" name="_csrf" value="'.csrf().'"><label class="check-label"><input name="enabled" type="checkbox" value="1" '.(!empty($profile['enabled'])?'checked':'').'><span>Monitor my registered email address</span></label><label>Recheck interval<select name="interval_days"><option value="7" '.(($profile['interval_days']??7)==7?'selected':'').'>Every 7 days</option><option value="14" '.(($profile['interval_days']??7)==14?'selected':'').'>Every 14 days</option><option value="30" '.(($profile['interval_days']??7)==30?'selected':'').'>Every 30 days</option></select></label><button class="button button-accent" '.(!in_array($u['plan'],['pro','business'],true)?'disabled':'').'>Save monitoring preferences ↗</button></form></section><section class="panel"><h2>Change history</h2>';
 $q=db()->prepare('SELECT event_type,created_at FROM monitoring_events WHERE user_id=? ORDER BY created_at DESC LIMIT 25');$q->execute([$u['id']]);$events=$q->fetchAll();
 if(!$events)echo '<p class="muted">No completed monitoring comparisons yet.</p>';
 foreach($events as $event)echo '<p><span class="status">'.esc(strtoupper($event['event_type'])).'</span> <small>'.esc($event['created_at']).' UTC</small></p>';
 echo '</section></div></main>';pageEnd();exit;
}
