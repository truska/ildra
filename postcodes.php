<?php
declare(strict_types=1);

/** Shared character rule; intentionally not restricted to one country's postcode shape. */
function normalise_postcode(string $value): string
{
    return strtoupper(trim(preg_replace('/[^A-Za-z0-9 ]/','',$value) ?? ''));
}

function is_postcode_field(string $name): bool
{
    return (bool)preg_match('/(?:^|[_\[])(?:postcode|postal_code)(?:\]|$)/i',$name);
}

function normalise_postcode_fields(array $data): array
{
    foreach ($data as $key=>$value) {
        if (is_array($value)) $data[$key]=normalise_postcode_fields($value);
        elseif (is_string($value) && is_postcode_field((string)$key)) $data[$key]=normalise_postcode($value);
    }
    return $data;
}

/** Full HTML responses get one shared formatter, including future/dynamic forms. */
function postcode_response_assets(string $html): string
{
    if (stripos($html,'</body>')===false || !preg_match('/<(?:html|body)\b/i',$html)) return $html;
    foreach (headers_list() as $header) if (stripos($header,'Content-Type:')===0 && stripos($header,'text/html')===false) return $html;
    if (str_contains($html,'data-postcode-formatter')) return $html;
    $base=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/index.php'),'/');
    if (str_ends_with($base,'/admin')) $base=substr($base,0,-6);
    $script='<script data-postcode-formatter src="'.htmlspecialchars($base.'/assets/postcodes.js',ENT_QUOTES,'UTF-8').'"></script>';
    return preg_replace('/<\/body\s*>/i',$script.'</body>',$html,1) ?? $html;
}

function shared_form_response(string $html): string
{
    return postcode_response_assets(csrf_protect_forms($html));
}
