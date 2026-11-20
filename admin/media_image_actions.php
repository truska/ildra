<?php
declare(strict_types=1);

/** Call only after the editor has authorised access and resolved its own batch. */
function admin_handle_image_delete(?PDO $pdo, ?array $batch, string $returnUrl, array &$alerts, string $action = 'delete_image'): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== $action) return false;
    if (!csrf_is_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $alerts[] = ['type'=>'danger', 'message'=>'Your session token expired. Reload the page and try again.'];
        return true;
    }
    try {
        if ($batch && mediaBatchDeleteImage($pdo, $batch, (int)($_POST['image_id'] ?? 0))) {
            $_SESSION['flash_success'] = 'Image permanently deleted.';
            header('Location: ' . $returnUrl, true, 303);
            exit;
        }
        $alerts[] = ['type'=>'danger', 'message'=>'Image not found in this gallery.'];
    } catch (Throwable $e) {
        $alerts[] = ['type'=>'danger', 'message'=>'The image could not be deleted. Please try again.'];
    }
    return true;
}

function admin_image_delete_button(array $batch, array $image): string
{
    $formId = 'delete-gallery-image-' . (int)$batch['id'] . '-' . (int)$image['id'];
    return '<button type="submit" class="btn btn-sm btn-danger text-white mt-2" title="Delete permanently" aria-label="Delete permanently" form="' . $formId . '"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>';
}

/** Separate forms prevent Delete from submitting unsaved parent/gallery edits. */
function admin_image_delete_forms(array $batch, array $images, array $context = [], string $action = 'delete_image', ?string $csrf = null): string
{
    $html = '';
    foreach ($images as $image) {
        $formId = 'delete-gallery-image-' . (int)$batch['id'] . '-' . (int)$image['id'];
        $html .= '<form method="post" id="' . $formId . '" data-confirm-message="Permanently delete this image and all generated sizes? This cannot be undone. Unsaved changes on this page will not be saved." data-confirm-style="danger" data-confirm-title="Delete image permanently?" data-confirm-button="Delete permanently">';
        $html .= $csrf === null ? csrf_input() : '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
        $html .= '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="image_id" value="' . (int)$image['id'] . '">';
        foreach ($context as $name => $value) $html .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
        $html .= '</form>';
    }
    return $html;
}

function admin_handle_record_image_delete(?PDO $pdo, string $kind, int $id, string $returnUrl, array &$alerts, string $scope = ''): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'remove_record_image') return false;
    if (!csrf_is_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $alerts[]=['type'=>'danger','message'=>'Your session token expired. Reload the page and try again.'];
        return true;
    }
    require_once __DIR__.'/../record_image_removal.php';
    try {
        if ($pdo && removeContentRecordImage($pdo,$kind,$id,$scope)) {
            $_SESSION['flash_success']='Image removed.';
            header('Location: '.$returnUrl,true,303);exit;
        }
        $alerts[]=['type'=>'danger','message'=>'No image was found on this record.'];
    } catch (Throwable $e) {
        $alerts[]=['type'=>'danger','message'=>'The image could not be removed. Please try again.'];
    }
    return true;
}

function admin_record_image_delete_button(string $kind, int $id): string
{
    $formId='remove-record-image-'.image_upload_slug($kind).'-'.$id;
    return '<button type="submit" class="btn btn-sm btn-danger text-white mt-2" title="Remove image" aria-label="Remove image" form="'.$formId.'"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>';
}

function admin_record_image_delete_form(string $kind, int $id, array $context = [], bool $libraryOnly = false): string
{
    $formId='remove-record-image-'.image_upload_slug($kind).'-'.$id;
    $message=$libraryOnly ? 'Remove the selected image from this record? The shared Library image will be kept.' : 'Remove the image from this record and delete its uploaded files? Shared Library images and files still used by other records will be kept.';
    $html='<form method="post" id="'.$formId.'" data-confirm-title="Remove image?" data-confirm-message="'.h($message.' Unsaved changes on this page will not be saved.').'" data-confirm-style="danger" data-confirm-button="Remove image">'.csrf_input().'<input type="hidden" name="action" value="remove_record_image">';
    foreach($context as $name=>$value)$html.='<input type="hidden" name="'.h((string)$name).'" value="'.h((string)$value).'">';
    return $html.'</form>';
}
