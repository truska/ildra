<?php
declare(strict_types=1);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function render_wysiwyg(string $value): string
{
    // Allow a small set of safe tags for admin-authored rich text.
    $allowed = '<p><br><br/><strong><b><em><i><u><ul><ol><li><a><span><div><img>';
    $html = strip_tags($value, $allowed);
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'ride_notes.php') {
        $html = '<style>.main-img,.thumb{border-radius:8px}@media (min-width:768px){main .card .row.g-4 > .col-md-5{order:2}}</style>' . $html;
    }
    return $html;
}

function tinymce_api_key(): string
{
    return '7dorpwqq3ijql244rl0nfkayvqy5uxys69khek91x2lqqazw';
}

function tinymce_base_config(): array
{
    return [
        'menubar' => 'file edit view format',
        'branding' => false,
        'plugins' => 'advlist autolink lists link image charmap anchor searchreplace visualblocks code fullscreen insertdatetime media table preview help wordcount',
        'toolbar' => 'undo redo | link | bold italic underline strikethrough | fontfamily fontsize blocks | alignleft aligncenter alignright alignjustify | outdent indent | numlist bullist | forecolor backcolor | pagebreak | charmap | fullscreen preview code | insertfile image media link anchor',
        'height' => 300,
        'default_link_target' => '_blank',
        'convert_urls' => false,
        'statusbar' => false,
        'toolbar_mode' => 'sliding',
    ];
}

function render_tinymce_bootstrap(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    $baseConfigJson = json_encode(tinymce_base_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
    <script src="https://cdn.tiny.cloud/1/<?php echo h(tinymce_api_key()); ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        window.ildraTinyMceBaseConfig = <?php echo $baseConfigJson ?: '{}'; ?>;
        window.ildraTinyMceConfig = function(overrides) {
            const base = Object.assign({}, window.ildraTinyMceBaseConfig || {});
            return Object.assign(base, overrides || {});
        };
        window.ildraNewsTinyMceConfig = function(selector) {
            const allowedTags = new Set(['P','BR','H2','H3','H4','BLOCKQUOTE','STRONG','B','EM','I','U','UL','OL','LI','A']);
            const removeTags = new Set(['SCRIPT','STYLE','META','LINK','TITLE','IMG','OBJECT','EMBED','IFRAME']);
            return window.ildraTinyMceConfig({
                selector: selector,
                paste_data_images: false,
                paste_webkit_styles: 'none',
                paste_remove_styles_if_webkit: true,
                valid_elements: 'p,br,h2,h3,h4,blockquote,strong,b,em,i,u,ul,ol,li,a[href|target|rel]',
                invalid_elements: 'script,style,meta,link,title,img,object,embed,iframe',
                paste_preprocess: function (plugin, args) {
                    const template = document.createElement('template');
                    template.innerHTML = args.content || '';
                    const comments = document.createTreeWalker(template.content, NodeFilter.SHOW_COMMENT);
                    const commentsToRemove = [];
                    while (comments.nextNode()) commentsToRemove.push(comments.currentNode);
                    commentsToRemove.forEach(function (comment) { comment.remove(); });
                    Array.from(template.content.querySelectorAll('*')).reverse().forEach(function (element) {
                        if (removeTags.has(element.tagName)) {
                            element.remove();
                            return;
                        }
                        if (!allowedTags.has(element.tagName)) {
                            element.replaceWith(...Array.from(element.childNodes));
                            return;
                        }
                        Array.from(element.attributes).forEach(function (attribute) {
                            if (element.tagName !== 'A' || !['href','target'].includes(attribute.name.toLowerCase())) {
                                element.removeAttribute(attribute.name);
                            }
                        });
                        if (element.tagName === 'A') {
                            const href = (element.getAttribute('href') || '').trim();
                            if (/^(?:javascript|data):/i.test(href)) element.removeAttribute('href');
                            if (element.getAttribute('target') === '_blank') element.setAttribute('rel', 'noopener');
                        }
                    });
                    const textNodes = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT);
                    while (textNodes.nextNode()) textNodes.currentNode.nodeValue = textNodes.currentNode.nodeValue.replace(/\u00a0/g, ' ');
                    args.content = template.innerHTML;
                }
            });
        };
    </script>
    <?php
}


function render_password_reveal_assets(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    ?>
    <style>
        .password-reveal-wrap {
            position: relative;
        }
        .password-reveal-wrap > input[type="password"],
        .password-reveal-wrap > input[data-password-reveal="1"] {
            padding-right: 3rem;
        }
        .password-reveal-btn {
            position: absolute;
            top: 50%;
            right: 0.55rem;
            width: 2rem;
            height: 2rem;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: #476146;
            line-height: 1;
            cursor: pointer;
            z-index: 2;
        }
        .password-reveal-btn:hover,
        .password-reveal-btn:focus-visible,
        .password-reveal-btn.is-revealing {
            background: rgba(20, 97, 24, 0.1);
            color: #146118;
            outline: none;
        }
        .password-reveal-btn svg {
            width: 1.1rem;
            height: 1.1rem;
            display: block;
        }
        .password-reveal-wrap > input.form-control-sm + .password-reveal-btn {
            width: 1.75rem;
            height: 1.75rem;
            right: 0.45rem;
        }
    </style>
    <script>
        (function() {
            const icon = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            const enhance = (input) => {
                if (!input || input.dataset.passwordReveal === '1' || input.disabled || input.readOnly) return;
                input.dataset.passwordReveal = '1';
                const wrap = document.createElement('span');
                wrap.className = 'password-reveal-wrap d-block';
                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'password-reveal-btn';
                btn.innerHTML = icon;
                btn.setAttribute('aria-label', 'Show password');
                btn.setAttribute('title', 'Show password');
                wrap.appendChild(btn);

                const show = () => {
                    input.type = 'text';
                    btn.classList.add('is-revealing');
                    btn.setAttribute('aria-label', 'Hide password');
                    btn.setAttribute('title', 'Hide password');
                };
                const hide = () => {
                    input.type = 'password';
                    btn.classList.remove('is-revealing');
                    btn.setAttribute('aria-label', 'Show password');
                    btn.setAttribute('title', 'Show password');
                };
                btn.addEventListener('mouseenter', show);
                btn.addEventListener('mouseleave', hide);
                btn.addEventListener('focus', show);
                btn.addEventListener('blur', hide);
                btn.addEventListener('touchstart', (event) => { event.preventDefault(); show(); }, {passive: false});
                btn.addEventListener('touchend', hide);
                btn.addEventListener('touchcancel', hide);
            };
            document.querySelectorAll('input[type="password"]').forEach(enhance);
        })();
    </script>
    <?php
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    // Replace non letter/digit with hyphens
    $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'event';
}

function price_to_number($price): float
{
    if (is_numeric($price)) {
        return (float)$price;
    }
    if (!is_string($price)) {
        return 0.0;
    }
    if (preg_match('/(-?\\d+(?:\\.\\d{1,2})?)/', $price, $m)) {
        return (float)$m[1];
    }
    return 0.0;
}

function format_price($price): string
{
    $numeric = price_to_number($price);
    // If we have a number, format with currency; otherwise default to zero with currency.
    if ($numeric !== 0.0 || (is_string($price) && preg_match('/\\d/', $price))) {
        return '£' . number_format($numeric, 2);
    }
    return '£0.00';
}

function class_names_from_pricing_rows(array $rows): array
{
    $names = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!empty($row['is_member_price']) || empty($row['enabled'])) {
            continue;
        }
        $label = trim((string)($row['class_name'] ?? $row['class_code'] ?? ''));
        if ($label === '') {
            continue;
        }
        $key = strtolower($label);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $label;
    }
    return $names;
}

function class_names_from_classes_offered($classesRaw): array
{
    $classesDecoded = json_decode((string)$classesRaw, true);
    if (is_array($classesDecoded) && $classesDecoded) {
        $names = [];
        $seen = [];
        foreach ($classesDecoded as $cls) {
            $label = trim((string)($cls['label'] ?? ($cls['code'] ?? '')));
            if ($label === '') {
                continue;
            }
            $key = strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $label;
        }
        return $names;
    }
    if (!empty($classesRaw)) {
        return [(string)$classesRaw];
    }
    return [];
}

function roleToLevel(string $role): int
{
    return match ($role) {
        'superadmin' => 6,
        'admin' => 5,
        'organiser' => 3,
        'user' => 1,
        default => 0,
    };
}

function entry_components_summary(array $metadata): string
{
    $components = $metadata['components'] ?? [];
    if (!$components || !is_array($components)) {
        return '';
    }
    $parts = [];
    foreach ($components as $comp) {
        $label = $comp['label'] ?? ($comp['name'] ?? 'Extra');
        $type = $comp['type'] ?? 'product';
        $price = $comp['price'] ?? 0;
        $value = trim((string)($comp['value'] ?? ''));
        $inputKind = (string)($comp['input_kind'] ?? 'checkbox');
        $quantity = max(0, (int)($comp['quantity'] ?? ($inputKind === 'quantity' ? $value : 0)));
        $suffix = '';
        if ($inputKind === 'quantity') {
            if ($quantity > 0) {
                $lineTotal = price_to_number($comp['line_total'] ?? ($quantity * price_to_number($price)));
                $suffix = ' x' . $quantity . ' (+' . format_price($lineTotal) . ')';
            }
        } elseif ($type === 'product' && price_to_number($price) !== 0.0) {
            $suffix = ' (+' . format_price($price) . ')';
        } elseif ($value !== '') {
            $suffix = ': ' . $value;
        }
        $parts[] = $label . $suffix;
    }
    return implode(', ', $parts);
}

function format_display_date($value, string $fallback = '—'): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('d M Y');
    }
    if ($value === null) {
        return $fallback;
    }
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        $ts = (int)$value;
        if ($ts <= 0) {
            return $fallback;
        }
        return date('d M Y', $ts);
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    // Be more strict for common DB formats first (avoids locale/strtotime edge cases).
    // UX: date display must be consistent across the app.
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('d M Y');
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $fallback;
    }
    return date('d M Y', $ts);
}

function format_display_datetime($value, string $fallback = '—'): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('d M Y H:i:s');
    }
    if ($value === null) {
        return $fallback;
    }
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        $ts = (int)$value;
        if ($ts <= 0) {
            return $fallback;
        }
        return date('d M Y H:i:s', $ts);
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    // Common DB datetime format.
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('d M Y H:i:s');
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $fallback;
    }
    return date('d M Y H:i:s', $ts);
}

function table_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function table_index_exists(PDO $pdo, string $table, string $index): bool
{
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :idx");
        $stmt->execute([':idx' => $index]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function table_index_on_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Column_name = :col");
        $stmt->execute([':col' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function table_index_count(PDO $pdo, string $table): int
{
    try {
        $rows = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll() ?: [];
        $names = [];
        foreach ($rows as $row) {
            $name = (string)($row['Key_name'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        return count($names);
    } catch (PDOException $e) {
        return 0;
    }
}
