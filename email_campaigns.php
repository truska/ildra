<?php
declare(strict_types=1);

function ensureEmailCampaignTables(?PDO $pdo): void
{
    if (!$pdo) return;
    ensureSiteSettingsTable($pdo);
    foreach ([
        'users' => [
            'general_email_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ride_notice_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'people' => [
            'general_email_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ride_notice_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ] as $table => $columns) {
        foreach ($columns as $column => $definition) {
            if (table_column_exists($pdo, $table, $column)) continue;
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_campaign_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, template_key VARCHAR(80) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL, category ENUM('operational','general','ride_notice') NOT NULL DEFAULT 'general',
        subject_template VARCHAR(255) NOT NULL, html_template LONGTEXT NOT NULL, text_template LONGTEXT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_campaigns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(180) NOT NULL, campaign_type VARCHAR(80) NOT NULL DEFAULT 'announcement',
        category ENUM('operational','general','ride_notice') NOT NULL DEFAULT 'general', template_id INT UNSIGNED NULL,
        audience_preset VARCHAR(80) NOT NULL DEFAULT 'all_users', event_id INT UNSIGNED NULL, membership_year SMALLINT UNSIGNED NULL,
        address_strategy ENUM('person_first','account_only','person_only') NOT NULL DEFAULT 'person_first', subject_template VARCHAR(255) NOT NULL,
        html_template LONGTEXT NOT NULL, text_template LONGTEXT NULL,
        status ENUM('draft','scheduled','sending','sent','paused','cancelled','failed') NOT NULL DEFAULT 'draft',
        scheduled_at DATETIME NULL, batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 25, live_send_approved TINYINT(1) NOT NULL DEFAULT 0,
        recipient_count INT UNSIGNED NOT NULL DEFAULT 0, sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0, opened_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_user_id INT UNSIGNED NULL, approved_by_user_id INT UNSIGNED NULL, approved_at DATETIME NULL,
        started_at DATETIME NULL, completed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_campaign_due (status, scheduled_at), INDEX idx_campaign_event (event_id), INDEX idx_campaign_template (template_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_campaign_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campaign_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, person_id INT UNSIGNED NULL,
        email VARCHAR(255) NOT NULL, email_normalized VARCHAR(255) NOT NULL, first_name VARCHAR(120) NULL, last_name VARCHAR(120) NULL,
        merge_json LONGTEXT NULL, tracking_token CHAR(40) NOT NULL, unsubscribe_token CHAR(40) NOT NULL,
        status ENUM('pending','sending','sent','failed','skipped','unsubscribed') NOT NULL DEFAULT 'pending', attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_message TEXT NULL, email_log_id INT UNSIGNED NULL, sent_at DATETIME NULL, first_opened_at DATETIME NULL,
        last_opened_at DATETIME NULL, open_count INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_campaign_email (campaign_id,email_normalized), UNIQUE KEY uniq_campaign_tracking (tracking_token),
        UNIQUE KEY uniq_campaign_unsubscribe (unsubscribe_token), INDEX idx_campaign_recipient_status (campaign_id,status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $setting=$pdo->prepare("INSERT IGNORE INTO site_settings (setting_key,setting_value,updated_at) VALUES (:key,:value,NOW())");
    foreach(['campaign_live_sending_enabled'=>'0','campaign_default_batch_size'=>'25','campaign_public_base_url'=>''] as $key=>$value)$setting->execute([':key'=>$key,':value'=>$value]);
}

function emailCampaignPresets(): array
{
    return [
        'all_members' => 'All current members',
        'expired_members' => 'Expired members not renewed',
        'non_members' => 'Registered non-members',
        'all_users' => 'All registered users',
        'event_entrants' => 'Entrants for a specific event',
    ];
}

function emailCampaignLocalNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
}

function emailCampaignBaseUrl(array $settings): string
{
    $configured = rtrim(trim((string)($settings['campaign_public_base_url'] ?? '')), '/');
    if ($configured !== '') return $configured;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if($host===''&&function_exists('email_environment_config')){
        $emailConfig=email_environment_config();$environment=(string)($emailConfig['environment']??'dev');
        $candidate=$environment==='live'?((array)($emailConfig['live_hosts']??[]))[0]??'':(string)($emailConfig['cli_host']??'');
        $host=preg_replace('/[^a-z0-9.:-]/i','',(string)$candidate);
    }
    return $host !== '' ? $scheme . '://' . $host : '';
}

function emailCampaignMerge(string $template, array $values): string
{
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function (array $m) use ($values): string {
        return array_key_exists(strtolower($m[1]), $values) ? (string)$values[strtolower($m[1])] : '';
    }, $template) ?? $template;
}

function emailCampaignEventMerge(?PDO $pdo, int $eventId, string $baseUrl): array
{
    if (!$pdo || $eventId <= 0) return [];
    $stmt = $pdo->prepare('SELECT id,title,event_date,venue,entry_close_at FROM events WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$eventId]); $event=$stmt->fetch(); if(!$event) return [];
    $eventUrl=$baseUrl.'/event.php?id='.(int)$event['id'];
    return [
        'event_title'=>(string)$event['title'], 'event_date'=>format_display_date($event['event_date']??null,''),
        'event_venue'=>(string)($event['venue']??''), 'entry_close_date'=>format_display_date($event['entry_close_at']??null,''),
        'event_url'=>$eventUrl, 'ride_notes_url'=>$baseUrl.'/ride_notes.php?event_id='.(int)$event['id'],
    ];
}

function emailCampaignRecipientRows(PDO $pdo, array $campaign): array
{
    ensureMembershipTables($pdo); ensure_bookings_tables($pdo);
    $preset=(string)($campaign['audience_preset']??'all_users'); $year=(int)($campaign['membership_year']??date('Y'));
    $strategy=(string)($campaign['address_strategy']??'person_first'); $params=[];
    if ($preset === 'event_entrants') {
        $sql="SELECT b.user_id, p.id person_id, COALESCE(NULLIF(p.first_name,''),u.first_name,b.contact_name) first_name,
                     COALESCE(NULLIF(p.last_name,''),u.last_name,'') last_name,
                     CASE WHEN :person_only='1' THEN p.email WHEN :account_only='1' THEN COALESCE(u.email,b.contact_email)
                          ELSE COALESCE(NULLIF(p.email,''),NULLIF(u.email,''),b.contact_email) END email,
                     p.member_number
              FROM booking_items bi JOIN bookings b ON b.id=bi.booking_id LEFT JOIN users u ON u.id=b.user_id
              LEFT JOIN people p ON p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(bi.metadata,'$.person_id')) AS UNSIGNED)
              WHERE bi.event_id=:event_id AND bi.is_withdrawn=0";
        $params=[':event_id'=>(int)($campaign['event_id']??0),':person_only'=>$strategy==='person_only'?'1':'0',':account_only'=>$strategy==='account_only'?'1':'0'];
    } else {
        $membershipJoin="LEFT JOIN membership_purchases mp ON mp.member_id=p.id AND mp.membership_year=:active_year AND mp.status<>'expired'";
        $where=['u.email IS NOT NULL'];
        if ($preset==='all_members') $where[]='mp.id IS NOT NULL';
        if ($preset==='expired_members') $where[]="EXISTS (SELECT 1 FROM membership_purchases oldmp WHERE oldmp.member_id=p.id AND oldmp.membership_year<:expired_before) AND mp.id IS NULL";
        if ($preset==='non_members') $where[]='NOT EXISTS (SELECT 1 FROM membership_purchases anymp JOIN people anyp ON anyp.id=anymp.member_id WHERE anyp.owner_user_id=u.id AND anymp.membership_year=:nonmember_year AND anymp.status<>\'expired\')';
        $sql="SELECT u.id user_id,p.id person_id,COALESCE(NULLIF(p.first_name,''),u.first_name) first_name,
                    COALESCE(NULLIF(p.last_name,''),u.last_name) last_name,
                    CASE WHEN :person_only='1' THEN p.email WHEN :account_only='1' THEN u.email ELSE COALESCE(NULLIF(p.email,''),u.email) END email,
                    p.email person_email,u.email account_email,p.member_number,u.general_email_opt_in user_general,u.ride_notice_opt_in user_ride,
                    p.general_email_opt_in person_general,p.ride_notice_opt_in person_ride
              FROM users u LEFT JOIN people p ON p.owner_user_id=u.id AND p.is_archived=0 {$membershipJoin}
              WHERE ".implode(' AND ',$where);
        $params=[':active_year'=>$year,':person_only'=>$strategy==='person_only'?'1':'0',':account_only'=>$strategy==='account_only'?'1':'0'];
        if($preset==='expired_members')$params[':expired_before']=$year;
        if($preset==='non_members')$params[':nonmember_year']=$year;
    }
    $stmt=$pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll()?:[];
}

function emailCampaignSnapshotRecipients(PDO $pdo, int $campaignId): array
{
    ensureEmailCampaignTables($pdo); $stmt=$pdo->prepare('SELECT * FROM email_campaigns WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$campaignId]); $campaign=$stmt->fetch(); if(!$campaign) return ['count'=>0,'skipped'=>0];
    $settings=getSiteSettings($pdo); $base=emailCampaignBaseUrl($settings); $event=emailCampaignEventMerge($pdo,(int)($campaign['event_id']??0),$base);
    $rows=emailCampaignRecipientRows($pdo,$campaign); $dedup=[]; $skipped=0;$strategy=(string)($campaign['address_strategy']??'person_first');
    foreach($rows as $row){
        $email=strtolower(trim((string)($row['email']??''))); if(!filter_var($email,FILTER_VALIDATE_EMAIL)){ $skipped++; continue; }
        $category=(string)$campaign['category'];
        $usesPerson=$strategy==='person_only'||($strategy==='person_first'&&filter_var(trim((string)($row['person_email']??'')),FILTER_VALIDATE_EMAIL));
        if($category==='general' && empty($row[$usesPerson?'person_general':'user_general'])){ $skipped++; continue; }
        if($category==='ride_notice' && empty($row[$usesPerson?'person_ride':'user_ride'])){ $skipped++; continue; }
        if(isset($dedup[$email])) continue;
        $first=trim((string)($row['first_name']??'')); $last=trim((string)($row['last_name']??''));
        $dedup[$email]=array_merge($event,[
            'campaign_name'=>(string)$campaign['name'],'first_name'=>$first,'last_name'=>$last,'full_name'=>trim($first.' '.$last),
            'email'=>$email,'member_number'=>(string)($row['member_number']??''),'membership_year'=>(string)($campaign['membership_year']??date('Y')),
            'membership_url'=>$base.'/memberships','logbook_url'=>$base.'/logbooks','current_date'=>emailCampaignLocalNow()->format('j F Y'),
            '_user_id'=>(int)($row['user_id']??0),'_person_id'=>(int)($row['person_id']??0),
            '_address_source'=>$usesPerson?'person':'account',
        ]);
    }
    $pdo->prepare('DELETE FROM email_campaign_recipients WHERE campaign_id=:id AND status IN (\'pending\',\'skipped\')')->execute([':id'=>$campaignId]);
    $ins=$pdo->prepare("INSERT IGNORE INTO email_campaign_recipients (campaign_id,user_id,person_id,email,email_normalized,first_name,last_name,merge_json,tracking_token,unsubscribe_token,status) VALUES (:campaign,:user,:person,:email,:normalized,:first,:last,:merge,:tracking,:unsubscribe,'pending')");
    foreach($dedup as $email=>$merge){ $ins->execute([':campaign'=>$campaignId,':user'=>$merge['_user_id']?:null,':person'=>$merge['_person_id']?:null,':email'=>$email,':normalized'=>$email,':first'=>$merge['first_name']?:null,':last'=>$merge['last_name']?:null,':merge'=>json_encode($merge,JSON_UNESCAPED_SLASHES),':tracking'=>sha1(random_bytes(32)),':unsubscribe'=>sha1(random_bytes(32))]); }
    $count=(int)$pdo->query('SELECT COUNT(*) FROM email_campaign_recipients WHERE campaign_id='.(int)$campaignId)->fetchColumn();
    $pdo->prepare('UPDATE email_campaigns SET recipient_count=:count WHERE id=:id')->execute([':count'=>$count,':id'=>$campaignId]);
    return ['count'=>$count,'skipped'=>$skipped];
}

function emailCampaignRenderRecipient(array $campaign,array $recipient,array $settings): array
{
    $merge=json_decode((string)($recipient['merge_json']??''),true); if(!is_array($merge))$merge=[];
    $base=emailCampaignBaseUrl($settings); $merge['unsubscribe_url']=$base.'/email_unsubscribe.php?t='.rawurlencode((string)$recipient['unsubscribe_token']);
    $subject=emailCampaignMerge((string)$campaign['subject_template'],$merge);
    $html=emailCampaignMerge((string)$campaign['html_template'],$merge);
    $text=emailCampaignMerge((string)($campaign['text_template']?:strip_tags((string)$campaign['html_template'])),$merge);
    if((string)$campaign['category']!=='operational'){
        $html.='<p style="margin-top:24px;font-size:12px;color:#667;">You can <a href="'.h($merge['unsubscribe_url']).'">manage or unsubscribe from these emails</a>.</p>';
        $text.="\n\nManage or unsubscribe: ".$merge['unsubscribe_url'];
    }
    $pixel=$base.'/email_open.php?t='.rawurlencode((string)$recipient['tracking_token']);
    if($base!=='')$html.='<img src="'.h($pixel).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">';
    return ['subject'=>$subject,'html'=>$html,'text'=>$text];
}

function emailCampaignRecipientStillConsented(PDO $pdo,array $campaign,array $recipient): bool
{
    $category=(string)($campaign['category']??'general'); if($category==='operational')return true;
    $column=$category==='ride_notice'?'ride_notice_opt_in':'general_email_opt_in';
    $merge=json_decode((string)($recipient['merge_json']??''),true);$source=is_array($merge)?(string)($merge['_address_source']??'account'):'account';
    if($source==='person'&&!empty($recipient['person_id'])){$stmt=$pdo->prepare("SELECT {$column} FROM people WHERE id=:id AND is_archived=0 LIMIT 1");$stmt->execute([':id'=>(int)$recipient['person_id']]);return (bool)$stmt->fetchColumn();}
    if(!empty($recipient['user_id'])){$stmt=$pdo->prepare("SELECT {$column} FROM users WHERE id=:id LIMIT 1");$stmt->execute([':id'=>(int)$recipient['user_id']]);return (bool)$stmt->fetchColumn();}
    return false;
}

function processEmailCampaignBatch(PDO $pdo,int $campaignId): array
{
    ensureEmailCampaignTables($pdo); $settings=getSiteSettings($pdo);
    if((string)($settings['campaign_live_sending_enabled']??'0')!=='1') return ['sent'=>0,'failed'=>0,'blocked'=>'Campaign live sending is disabled.'];
    $stmt=$pdo->prepare("SELECT * FROM email_campaigns WHERE id=:id AND status IN ('scheduled','sending') AND live_send_approved=1 LIMIT 1"); $stmt->execute([':id'=>$campaignId]); $campaign=$stmt->fetch();
    if(!$campaign)return ['sent'=>0,'failed'=>0,'blocked'=>'Campaign is not approved for sending.'];
    $limit=max(1,min(500,(int)$campaign['batch_size'])); $pdo->prepare("UPDATE email_campaigns SET status='sending',started_at=COALESCE(started_at,NOW()) WHERE id=:id")->execute([':id'=>$campaignId]);
    $rec=$pdo->prepare("SELECT * FROM email_campaign_recipients WHERE campaign_id=:id AND status='pending' ORDER BY id LIMIT {$limit}");$rec->execute([':id'=>$campaignId]);$sent=0;$failed=0;
    foreach($rec->fetchAll() as $recipient){
        if(!emailCampaignRecipientStillConsented($pdo,$campaign,$recipient)){
            $pdo->prepare("UPDATE email_campaign_recipients SET status='unsubscribed',error_message='Preference withdrawn before delivery.' WHERE id=:id")->execute([':id'=>(int)$recipient['id']]);
            continue;
        }
        $payload=emailCampaignRenderRecipient($campaign,$recipient,$settings); $emailSettings=getEmailSettings($pdo);
        $beforeLogId=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM email_log')->fetchColumn();
        $ok=send_logged_email($pdo,(string)$recipient['email'],subject_with_prefix($emailSettings,$payload['subject']),wrap_user_email_html($settings,$emailSettings,$payload['html']),wrap_user_email_text($settings,$emailSettings,$payload['text']),['kind'=>'campaign','campaign_id'=>$campaignId,'campaign_recipient_id'=>(int)$recipient['id']]);
        $logStmt=$pdo->prepare('SELECT id FROM email_log WHERE id>:after_id AND meta_json LIKE :marker ORDER BY id DESC LIMIT 1');
        $logStmt->execute([':after_id'=>$beforeLogId,':marker'=>'%"campaign_recipient_id":'.(int)$recipient['id'].'%']);$logId=(int)($logStmt->fetchColumn()?:0);
        $status=$ok?'sent':'failed'; $ok?$sent++:$failed++;
        $up=$pdo->prepare('UPDATE email_campaign_recipients SET status=:status,attempt_count=attempt_count+1,email_log_id=:log,sent_at=IF(:sent=1,NOW(),sent_at),error_message=IF(:sent=1,NULL,\'Delivery failed; see email log.\') WHERE id=:id');
        $up->execute([':status'=>$status,':log'=>$logId?:null,':sent'=>$ok?1:0,':id'=>(int)$recipient['id']]);
    }
    $counts=$pdo->prepare("SELECT SUM(status='sent') sent,SUM(status='failed') failed,SUM(status='pending') pending FROM email_campaign_recipients WHERE campaign_id=:id");$counts->execute([':id'=>$campaignId]);$c=$counts->fetch();$done=(int)($c['pending']??0)===0;
    $pdo->prepare('UPDATE email_campaigns SET sent_count=:sent,failed_count=:failed,status=:status,completed_at=IF(:done=1,NOW(),NULL) WHERE id=:id')->execute([':sent'=>(int)($c['sent']??0),':failed'=>(int)($c['failed']??0),':status'=>$done?'sent':'sending',':done'=>$done?1:0,':id'=>$campaignId]);
    return ['sent'=>$sent,'failed'=>$failed,'blocked'=>''];
}

function processDueEmailCampaigns(PDO $pdo): array
{
    ensureEmailCampaignTables($pdo); $now=emailCampaignLocalNow()->format('Y-m-d H:i:s');
    $stmt=$pdo->prepare("SELECT id FROM email_campaigns WHERE status IN ('scheduled','sending') AND live_send_approved=1 AND (scheduled_at IS NULL OR scheduled_at<=:now) ORDER BY id LIMIT 20");$stmt->execute([':now'=>$now]);
    $out=[];foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)$out[(int)$id]=processEmailCampaignBatch($pdo,(int)$id);return $out;
}
