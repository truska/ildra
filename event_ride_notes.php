<?php
declare(strict_types=1);

function ensureRideNotesTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_ride_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL,
        status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft', intro_html MEDIUMTEXT NULL,
        ride_notes_html MEDIUMTEXT NULL, ctr_notes_html MEDIUMTEXT NULL,
        pdf_filename VARCHAR(255) NULL, pdf_original_filename VARCHAR(255) NULL,
        completed_by INT UNSIGNED NULL, completed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_ride_notes_event (event_id), INDEX idx_event_ride_notes_status (status)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['pdf_filename'=>'VARCHAR(255) NULL AFTER ctr_notes_html','pdf_original_filename'=>'VARCHAR(255) NULL AFTER pdf_filename'] as $column=>$definition) {
        try { $pdo->exec("ALTER TABLE event_ride_notes ADD COLUMN IF NOT EXISTS {$column} {$definition}"); } catch (Throwable $e) { }
    }
    try {
        $pdo->exec("ALTER TABLE event_ride_notes MODIFY COLUMN status ENUM('draft','complete','published','hidden') NOT NULL DEFAULT 'draft'");
        $pdo->exec("UPDATE event_ride_notes SET status = 'published' WHERE status = 'complete'");
        $pdo->exec("ALTER TABLE event_ride_notes MODIFY COLUMN status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft'");
    } catch (Throwable $e) { }
}

function rideNotesDefaultSettings(array $settings): array
{
    return ['intro_html'=>(string)($settings['ride_notes_email_intro_html'] ?? '<p>Important information for your upcoming ride is now available.</p>'), 'signature_html'=>(string)($settings['ride_notes_email_signature_html'] ?? ''), 'header_image_url'=>trim((string)($settings['ride_notes_email_header_image_url'] ?? ''))];
}

function fetchRideNotes(?PDO $pdo, int $eventId): ?array
{
    if (!$pdo || $eventId <= 0) return null;
    ensureRideNotesTables($pdo); $stmt=$pdo->prepare('SELECT * FROM event_ride_notes WHERE event_id=:event_id LIMIT 1'); $stmt->execute([':event_id'=>$eventId]); return $stmt->fetch() ?: null;
}

function saveRideNotes(PDO $pdo, int $eventId, array $data, int $userId): bool
{
    ensureRideNotesTables($pdo); $status=in_array((string)($data['status']??''),['draft','published','hidden'],true)?(string)$data['status']:'draft';
    $stmt=$pdo->prepare("INSERT INTO event_ride_notes (event_id,status,intro_html,ride_notes_html,ctr_notes_html,completed_by,completed_at) VALUES (:event_id,:status,:intro,:ride,:ctr,:completed_by,:completed_at) ON DUPLICATE KEY UPDATE status=VALUES(status),intro_html=VALUES(intro_html),ride_notes_html=VALUES(ride_notes_html),ctr_notes_html=VALUES(ctr_notes_html),completed_by=VALUES(completed_by),completed_at=VALUES(completed_at)");
    return $stmt->execute([':event_id'=>$eventId,':status'=>$status,':intro'=>trim((string)($data['intro_html']??''))?:null,':ride'=>trim((string)($data['ride_notes_html']??''))?:null,':ctr'=>trim((string)($data['ctr_notes_html']??''))?:null,':completed_by'=>$status==='published'?$userId:null,':completed_at'=>$status==='published'?date('Y-m-d H:i:s'):null]);
}

function rideNotesPublicUrl(string $siteBase, int $eventId): string { return rtrim($siteBase, '/') . '/ride_notes.php?event_id=' . $eventId; }

function rideNotesCurrentPlatformUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
    $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/[\r\n]/', '', $host) ?: '';
    return $host !== '' ? $scheme . '://' . $host . $path : $path;
}

function rideNotesPdfDirectory(): string { return __DIR__ . '/filestore/files/ride-notes'; }
function rideNotesPdfUrl(string $filename): string { return '/filestore/files/ride-notes/' . rawurlencode(basename($filename)); }
function rideNotesPdfAttachment(array $notes): ?array
{
    $filename=basename((string)($notes['pdf_filename']??'')); if($filename==='') return null; $path=rideNotesPdfDirectory().'/'.$filename;
    return is_file($path)&&is_readable($path) ? ['path'=>$path,'filename'=>(string)($notes['pdf_original_filename']??$filename),'mime_type'=>'application/pdf'] : null;
}
function rideNotesStorePdf(array $file, array $event, ?string &$error): ?array
{
    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) { $error='Please select a PDF file.'; return null; }
    if (($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) { $error='PDF upload failed (code '.(int)$file['error'].').'; return null; }
    if ((int)($file['size']??0)>10*1024*1024) { $error='PDF files must be 10 MB or smaller (the current server upload limit).'; return null; }
    $tmp=(string)($file['tmp_name']??''); $signature=$tmp!==''?@file_get_contents($tmp,false,null,0,5):false; $finfo=$tmp!==''&&function_exists('finfo_open')?finfo_open(FILEINFO_MIME_TYPE):false; $mime=$finfo?(string)finfo_file($finfo,$tmp):''; if($finfo) finfo_close($finfo);
    if ($signature!=='%PDF-' || ($mime!==''&&!in_array($mime,['application/pdf','application/x-pdf'],true))) { $error='The uploaded file is not a valid PDF.'; return null; }
    $date=preg_replace('/[^0-9-]/','',(string)($event['event_date']??''))?:date('Y-m-d'); $title=function_exists('image_upload_slug')?image_upload_slug((string)($event['title']??'ride')):'ride'; $filename='ride-notes-'.(int)($event['id']??0).'-'.$date.'-'.$title.'.pdf'; $dir=rideNotesPdfDirectory();
    if(!is_dir($dir)) { $error='Ride Notes PDF storage has not been prepared. Please contact an administrator.'; return null; } if(!is_writable($dir)) { $error='Ride Notes PDF storage is not writable. Please check filestore/files/ride-notes permissions.'; return null; }
    if(!move_uploaded_file($tmp,$dir.'/'.$filename)) { $error='Unable to save the PDF. Please check filestore/files/ride-notes permissions.'; return null; } @chmod($dir.'/'.$filename,0664); return ['filename'=>$filename,'original_filename'=>basename((string)($file['name']??$filename))];
}
function saveRideNotesPdf(PDO $pdo, int $eventId, array $pdf): bool
{
    ensureRideNotesTables($pdo); $stmt=$pdo->prepare('INSERT INTO event_ride_notes (event_id,pdf_filename,pdf_original_filename) VALUES (:event_id,:filename,:original) ON DUPLICATE KEY UPDATE pdf_filename=VALUES(pdf_filename),pdf_original_filename=VALUES(pdf_original_filename)'); return $stmt->execute([':event_id'=>$eventId,':filename'=>$pdf['filename'],':original'=>$pdf['original_filename']]);
}

function rideNotesEmailPayload(array $event, array $notes, array $siteSettings, array $emailSettings, string $siteBase): array
{
    $defaults=rideNotesDefaultSettings($siteSettings); $intro=trim((string)($notes['intro_html']??'')) ?: $defaults['intro_html'];
    $url=rideNotesCurrentPlatformUrl(rideNotesPublicUrl($siteBase,(int)$event['id'])); $title=(string)($event['title']??'your upcoming ride'); $date=format_display_date($event['event_date']??null,''); $headerImage=$defaults['header_image_url']; $brandName=trim((string)($siteSettings['hero_title'] ?? email_brand_name($siteSettings,$emailSettings)));
    if($headerImage!=='') $header='<div style="margin:0 0 18px;"><img src="'.h($headerImage).'" alt="'.h($brandName).'" style="display:block;max-width:160px;max-height:90px;width:auto;height:auto;"></div>'; else { $logo=email_brand_logo_url($siteSettings); $header='<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 18px;"><tr><td style="padding-right:14px;color:#146118;font-size:25px;line-height:1.15;font-weight:800;">'.h($brandName).'</td>'.($logo!==''?'<td><img src="'.h($logo).'" alt="'.h($brandName).'" style="display:block;max-width:90px;max-height:60px;width:auto;height:auto;"></td>':'').'</tr></table>'; }
    $fullUrl='<p style="margin:16px 0 0;color:#476146;font-size:12px;line-height:1.45;word-break:break-all;">If the button does not work, copy and paste this link into your browser:<br><a href="'.h($url).'" style="color:#146118;">'.h($url).'</a></p>';
    $inner=$header.'<h2 style="margin:0 0 12px;color:#0c2a12;">Ride Notes</h2><p style="margin:0 0 16px;"><strong>'.h($title.($date?' — '.$date:'')).'</strong></p>'.$intro.'<div style="margin-top:20px;">'.email_cta_button_html($url,'View Ride Notes').'</div>'.$fullUrl.(trim($defaults['signature_html'])!==''?'<div style="margin-top:20px;">'.$defaults['signature_html'].'</div>':''); $text="Ride Notes\n\n{$title}".($date?" — {$date}":'')."\n\n".trim(strip_tags($intro))."\n\nView Ride Notes: {$url}";
    return ['subject'=>subject_with_prefix($emailSettings,'Ride Notes: '.$title),'html'=>wrap_user_email_html($siteSettings,$emailSettings,$inner),'text'=>wrap_user_email_text($siteSettings,$emailSettings,$text)];
}
