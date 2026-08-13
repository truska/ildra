<?php
declare(strict_types=1);

/** Reusable image batches for banners, galleries and content records. */
function ensureMediaBatchTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS media_batches (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        purpose VARCHAR(80) NOT NULL,
        owner_type VARCHAR(80) NOT NULL,
        owner_id INT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(150) NOT NULL,
        section VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_media_batch_owner (purpose, owner_type, owner_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS media_batch_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_id INT UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) DEFAULT NULL,
        title VARCHAR(255) DEFAULT NULL,
        alt_text VARCHAR(255) DEFAULT NULL,
        caption TEXT DEFAULT NULL,
        available_sizes VARCHAR(100) DEFAULT NULL,
        display_order INT NOT NULL DEFAULT 100,
        lightbox_enabled TINYINT(1) NOT NULL DEFAULT 1,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_media_batch_images (batch_id, archived, display_order),
        CONSTRAINT fk_media_batch_images_batch FOREIGN KEY (batch_id) REFERENCES media_batches(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if (!table_column_exists($pdo, 'media_batch_images', 'lightbox_enabled')) {
        $pdo->exec("ALTER TABLE media_batch_images ADD COLUMN lightbox_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER display_order");
    }
}

function mediaBatchGetOrCreate(?PDO $pdo, string $purpose, string $ownerType, int $ownerId, string $name, string $section): ?array
{
    if (!$pdo) return null;
    ensureMediaBatchTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM media_batches WHERE purpose=:purpose AND owner_type=:owner_type AND owner_id=:owner_id LIMIT 1');
    $stmt->execute([':purpose'=>$purpose, ':owner_type'=>$ownerType, ':owner_id'=>$ownerId]);
    $batch = $stmt->fetch();
    if (!$batch) {
        $stmt = $pdo->prepare('INSERT INTO media_batches (purpose,owner_type,owner_id,name,section) VALUES (:purpose,:owner_type,:owner_id,:name,:section)');
        $stmt->execute([':purpose'=>$purpose, ':owner_type'=>$ownerType, ':owner_id'=>$ownerId, ':name'=>$name, ':section'=>image_upload_section($section)]);
        $batch = ['id'=>(int)$pdo->lastInsertId(), 'purpose'=>$purpose, 'owner_type'=>$ownerType, 'owner_id'=>$ownerId, 'name'=>$name, 'section'=>image_upload_section($section)];
    }
    return $batch;
}

function mediaBatchFind(?PDO $pdo, string $purpose, string $ownerType, int $ownerId): ?array
{
    if (!$pdo) return null;
    ensureMediaBatchTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM media_batches WHERE purpose=:purpose AND owner_type=:owner_type AND owner_id=:owner_id LIMIT 1');
    $stmt->execute([':purpose'=>$purpose, ':owner_type'=>$ownerType, ':owner_id'=>$ownerId]);
    return $stmt->fetch() ?: null;
}

function mediaBatchImages(?PDO $pdo, int $batchId, bool $includeArchived = false): array
{
    if (!$pdo || $batchId <= 0) return [];
    ensureMediaBatchTables($pdo);
    $sql = 'SELECT * FROM media_batch_images WHERE batch_id=:batch_id' . ($includeArchived ? '' : ' AND archived=0') . ' ORDER BY display_order,id';
    $stmt = $pdo->prepare($sql); $stmt->execute([':batch_id'=>$batchId]);
    return $stmt->fetchAll() ?: [];
}

function mediaBatchImageUrl(array $batch, array $image, string $size = 'original'): string
{
    $available = array_values(array_filter(explode(',', (string)($image['available_sizes'] ?? ''))));
    if (!in_array($size, $available, true)) $size = in_array('original', $available, true) ? 'original' : ($available[0] ?? 'original');
    return image_upload_public_path((string)$batch['section'], $size, (string)$image['filename']);
}

function mediaBatchUpload(?PDO $pdo, array $batch, array $files, array $sizes, array &$errors): int
{
    if (!$pdo) return 0;
    $current = mediaBatchImages($pdo, (int)$batch['id'], true);
    $nextOrder = $current ? max(array_map(static fn(array $row): int => (int)$row['display_order'], $current)) + 10 : 10;
    $results = image_upload_many($files, ['section'=>$batch['section'], 'sizes'=>$sizes], $errors);
    $sourceFiles = array_values(array_filter(image_upload_files($files), static fn(array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    $stmt = $pdo->prepare('INSERT INTO media_batch_images (batch_id,filename,original_filename,title,alt_text,available_sizes,display_order) VALUES (:batch,:filename,:original,:title,:alt,:sizes,:ord)');
    foreach ($results as $index => $result) {
        $original = (string)($sourceFiles[$index]['name'] ?? $result['filename']);
        $title = trim(str_replace(['-', '_'], ' ', pathinfo($original, PATHINFO_FILENAME)));
        $stmt->execute([':batch'=>$batch['id'], ':filename'=>$result['filename'], ':original'=>$original, ':title'=>$title ?: null, ':alt'=>$title ?: null, ':sizes'=>implode(',', array_keys($result['sizes'])), ':ord'=>$nextOrder]);
        $nextOrder += 10;
    }
    return count($results);
}
