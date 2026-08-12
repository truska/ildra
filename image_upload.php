<?php
declare(strict_types=1);

/**
 * Shared image upload pipeline.
 *
 * Files are stored as filestore/images/{section}/{size}/{same-filename}.
 * A caller chooses which renditions to create, so not every image needs every size.
 */

function image_upload_slug(string $value): string
{
    $value = trim(mb_strtolower($value));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = strtolower($ascii);
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'image';
}

function image_upload_section(string $section): string
{
    return image_upload_slug(str_replace('/', '-', $section));
}

function image_upload_public_path(string $section, string $size, string $filename): string
{
    return '/filestore/images/' . image_upload_section($section) . '/' . image_upload_slug($size) . '/' . rawurlencode(basename($filename));
}

function image_upload_files(array $input): array
{
    if (!is_array($input['name'] ?? null)) {
        return [$input];
    }
    $files = [];
    foreach ($input['name'] as $i => $name) {
        $files[] = [
            'name' => $name,
            'type' => $input['type'][$i] ?? '',
            'tmp_name' => $input['tmp_name'][$i] ?? '',
            'error' => $input['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'][$i] ?? 0,
        ];
    }
    return $files;
}

function image_upload_source(array $file, int $maxBytes, int $maxPixels, ?string &$error): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'No image uploaded.';
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed (code ' . (int)$file['error'] . ').';
        return null;
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        $error = 'Image file is too large.';
        return null;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli')) {
        $error = 'Uploaded image was not received correctly.';
        return null;
    }
    $info = @getimagesize($tmp);
    if (!$info || empty($info[0]) || empty($info[1]) || (int)$info[0] * (int)$info[1] > $maxPixels) {
        $error = 'Please upload a valid image with reasonable dimensions.';
        return null;
    }
    $type = (int)($info[2] ?? 0);
    $image = false;
    $extension = '';
    if ($type === IMAGETYPE_JPEG) {
        $image = @imagecreatefromjpeg($tmp);
        $extension = 'jpg';
    } elseif ($type === IMAGETYPE_PNG) {
        $image = @imagecreatefrompng($tmp);
        $extension = 'png';
    } elseif ($type === IMAGETYPE_GIF) {
        $image = @imagecreatefromgif($tmp);
        $extension = 'gif';
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $image = @imagecreatefromwebp($tmp);
        $extension = 'webp';
    }
    if (!$image || $extension === '') {
        $error = 'Please upload a JPG, PNG, GIF or WebP image.';
        return null;
    }
    if (function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($image);
    }
    imagealphablending($image, true);
    imagesavealpha($image, true);
    return ['image' => $image, 'width' => (int)$info[0], 'height' => (int)$info[1], 'extension' => $extension];
}

function image_upload_resize($source, int $sourceWidth, int $sourceHeight, ?int $targetWidth)
{
    if (!$targetWidth || $targetWidth >= $sourceWidth) {
        return $source;
    }
    $height = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
    $canvas = imagecreatetruecolor($targetWidth, $height);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $height, $transparent);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $height, $sourceWidth, $sourceHeight);
    return $canvas;
}

function image_upload_save($image, string $path, string $extension): bool
{
    return match ($extension) {
        'jpg' => imagejpeg($image, $path, 88),
        'png' => imagepng($image, $path, 6),
        'gif' => imagegif($image, $path),
        'webp' => function_exists('imagewebp') && imagewebp($image, $path, 84),
        default => false,
    };
}

function image_upload_one(array $file, array $options, ?string &$error = null): ?array
{
    if (!extension_loaded('gd')) {
        $error = 'Image uploads are unavailable because GD is not installed.';
        return null;
    }
    $section = image_upload_section((string)($options['section'] ?? 'content'));
    $sizes = (array)($options['sizes'] ?? ['original' => null]);
    if (!$sizes) {
        $error = 'At least one image size is required.';
        return null;
    }
    $source = image_upload_source($file, (int)($options['max_bytes'] ?? 10 * 1024 * 1024), (int)($options['max_pixels'] ?? 40000000), $error);
    if (!$source) {
        return null;
    }
    $uploadedStem = pathinfo((string)($file['name'] ?? 'image'), PATHINFO_FILENAME);
    $baseName = !empty($options['rename']) ? (string)($options['base_name'] ?? $uploadedStem) : $uploadedStem;
    $stem = image_upload_slug($baseName);
    $extension = (string)$source['extension'];
    $filename = $stem . '.' . $extension;
    $root = __DIR__ . '/filestore/images/' . $section;
    if (empty($options['overwrite'])) {
        for ($i = 0; $i < 1000; $i++) {
            $candidate = $stem . ($i ? '-' . $i : '') . '.' . $extension;
            $exists = false;
            foreach (array_keys($sizes) as $size) {
                if (is_file($root . '/' . image_upload_slug((string)$size) . '/' . $candidate)) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $filename = $candidate;
                break;
            }
        }
    }
    $paths = [];
    foreach ($sizes as $size => $width) {
        $safeSize = image_upload_slug((string)$size);
        $dir = $root . '/' . $safeSize;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            imagedestroy($source['image']);
            $error = 'Unable to create image folder.';
            return null;
        }
        $canvas = image_upload_resize($source['image'], $source['width'], $source['height'], $width === null ? null : (int)$width);
        $target = $dir . '/' . $filename;
        if (!image_upload_save($canvas, $target, $extension)) {
            if ($canvas !== $source['image']) imagedestroy($canvas);
            imagedestroy($source['image']);
            $error = 'Unable to save resized image.';
            return null;
        }
        @chmod($target, 0664);
        $paths[$safeSize] = image_upload_public_path($section, $safeSize, $filename);
        if ($canvas !== $source['image']) imagedestroy($canvas);
    }
    imagedestroy($source['image']);
    return ['filename' => $filename, 'section' => $section, 'sizes' => $paths];
}

function image_upload_many(array $input, array $options, array &$errors = []): array
{
    $results = [];
    foreach (image_upload_files($input) as $index => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $itemOptions = $options;
        if (!empty($options['rename']) && count(image_upload_files($input)) > 1) {
            $itemOptions['base_name'] = (string)($options['base_name'] ?? 'image') . '-' . ($index + 1);
        }
        $error = null;
        $result = image_upload_one($file, $itemOptions, $error);
        if ($result) $results[] = $result;
        else $errors[] = $error ?: 'Unable to upload image ' . ($index + 1) . '.';
    }
    return $results;
}
