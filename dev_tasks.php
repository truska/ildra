<?php
declare(strict_types=1);

function ensureDevTaskTables(?PDO $pdo): void
{
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS dev_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        created_by INT UNSIGNED NOT NULL,
        closed_by INT UNSIGNED DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_dev_tasks_status_priority (status, priority, updated_at),
        INDEX idx_dev_tasks_created_by (created_by)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dev_task_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        author_name VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        image_filename VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dev_task_messages_task (task_id, created_at, id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function devTaskAuthorName(array $user): string
{
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['email'] ?? 'Admin user');
}

function devTaskUpload(array $file, array &$alerts): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $error = null;
    $result = image_upload_one($file, [
        'section' => 'dev-tasks',
        'sizes' => ['original' => null, 'lg' => 1600, 'sm' => 400],
        'max_bytes' => 10 * 1024 * 1024,
    ], $error);
    if (!$result) $alerts[] = ['type' => 'danger', 'message' => $error ?: 'The screenshot could not be uploaded.'];
    return $result['filename'] ?? null;
}

function devTaskCreate(PDO $pdo, array $data, array $file, array $user, array &$alerts): ?int
{
    $title = trim((string)($data['title'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));
    $priority = (int)($data['priority'] ?? 3);
    if ($title === '') $alerts[] = ['type' => 'danger', 'message' => 'Please enter a task title.'];
    if ($message === '') $alerts[] = ['type' => 'danger', 'message' => 'Please describe the task, question or fault.'];
    if ($priority < 1 || $priority > 5) $alerts[] = ['type' => 'danger', 'message' => 'Priority must be between 1 and 5.'];
    if ($alerts) return null;
    $image = devTaskUpload($file, $alerts);
    if ($alerts) return null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO dev_tasks (title, priority, status, created_by) VALUES (:title,:priority,'open',:user)");
        $stmt->execute([':title'=>$title, ':priority'=>$priority, ':user'=>(int)$user['id']]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO dev_task_messages (task_id,user_id,author_name,message,image_filename) VALUES (:task,:user,:author,:message,:image)');
        $stmt->execute([':task'=>$id, ':user'=>(int)$user['id'], ':author'=>devTaskAuthorName($user), ':message'=>$message, ':image'=>$image]);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[] = ['type'=>'danger', 'message'=>'The task could not be saved.'];
        return null;
    }
}

function devTaskAddMessage(PDO $pdo, int $taskId, string $message, array $file, array $user, array &$alerts): bool
{
    $message = trim($message);
    if ($message === '') $alerts[] = ['type'=>'danger', 'message'=>'Please enter a reply.'];
    if ($alerts) return false;
    $image = devTaskUpload($file, $alerts);
    if ($alerts) return false;
    $stmt = $pdo->prepare('INSERT INTO dev_task_messages (task_id,user_id,author_name,message,image_filename) SELECT id,:user,:author,:message,:image FROM dev_tasks WHERE id=:task');
    $stmt->execute([':user'=>(int)$user['id'], ':author'=>devTaskAuthorName($user), ':message'=>$message, ':image'=>$image, ':task'=>$taskId]);
    if (!$stmt->rowCount()) { $alerts[]=['type'=>'danger','message'=>'Task not found.']; return false; }
    $pdo->prepare('UPDATE dev_tasks SET updated_at=NOW() WHERE id=:id')->execute([':id'=>$taskId]);
    return true;
}
