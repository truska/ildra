<?php
// Final render pass: preserve administrator-entered terms HTML in the print page.
ob_start();
register_shutdown_function(static function():void{
    global $c;
    $html=(string)ob_get_clean();
    if(is_array($c??null)){
        $raw=trim((string)($c['terms_html']??''));
        $plain=trim(strip_tags($raw));
        if($plain!=='')$html=str_replace(nl2br(h($plain)),$raw,$html);
    }
    echo $html;
});
?>
<style>
.sheet .scope{top:74.2%!important;z-index:2;padding:0 12mm;color:#174c31!important}
.sheet .dates small+b+small{margin-top:2.2mm!important}
.sheet:after{content:"";position:absolute;left:0;right:0;bottom:0;height:2.4%;background:#064528;z-index:1}
</style>
