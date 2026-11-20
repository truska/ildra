<?php
declare(strict_types=1);
// Included before admin_layout_start so the shared Back-button styles load in <head>.
$GLOBALS['campaignNavigationEnabled']=true;

function campaign_automated_template_keys(): array { return ['entries_open_members','entries_open_non_members','entries_closing','weekly_ride_notice']; }
function campaign_navigation_automatic(array $campaign): bool
{
    return in_array((string)($campaign['campaign_type']??''),['event_entries_open_members','event_entries_open_non_members','event_entries_closing','weekly_ride_notice'],true);
}

/** Canonical, allowlisted admin destinations only; never trust arbitrary return URLs. */
function campaign_navigation_return(mixed $value): ?string
{
    if (!is_string($value) || strlen($value)>3000 || preg_match('/[\r\n#]/',$value)) return null;
    $parts=parse_url($value);
    if (!$parts || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['fragment'])) return null;
    $path=$parts['path']??'';$q=[];parse_str($parts['query']??'',$q);
    foreach($q as $v) if(!is_string($v))return null;
    $keep=[];
    if ($path==='campaigns.php') {
        if(isset($q['tab']) && in_array($q['tab'],['manual','automatic'],true)) $keep=['tab'=>$q['tab']];
        elseif(in_array($q['view']??'',['templates','template_select'],true)) $keep=['view'=>$q['view']];
        elseif(($q['view']??'')==='edit' && ctype_digit($q['id']??'') && (int)$q['id']>0) $keep=['view'=>'edit','id'=>(int)$q['id']];
        else return null;
    } elseif($path==='email.php') {
        if(isset($q['view']) && $q['view']!=='settings' && $q['view']!=='log')return null;
        foreach(['view','page','sort','dir','filter_id','filter_to','filter_subject','filter_status','filter_sent'] as $key) if(isset($q[$key]))$keep[$key]=substr($q[$key],0,500);
    } elseif($path==='ride_notes.php') {
        if(!ctype_digit($q['event_id']??'') || (int)$q['event_id']<=0)return null;
        $keep=['event_id'=>(int)$q['event_id']];
    } elseif($path==='migration_campaign.php') {
        if($q)return null;
    } else return null;
    return $path.($keep?'?'.http_build_query($keep):'');
}

function campaign_navigation_request_return(): ?string
{
    return campaign_navigation_return($_POST['return']??$_GET['return']??null);
}
function campaign_navigation_with_return(string $url): string
{
    $return=campaign_navigation_request_return();
    return $url.($return?(str_contains($url,'?')?'&':'?').'return='.rawurlencode($return):'');
}
function campaign_navigation_back_label(string $url): string
{
    if(str_starts_with($url,'ride_notes.php'))return 'Back to Rider Notes';
    if($url==='migration_campaign.php')return 'Back to Account migration';
    if(str_starts_with($url,'email.php?view=settings'))return 'Back to Email settings';
    if(str_starts_with($url,'email.php'))return 'Back to Email history';
    if(str_contains($url,'view=template_select'))return 'Back to Choose a template';
    if(str_contains($url,'view=templates'))return 'Back to Templates';
    if(str_contains($url,'view=edit'))return 'Back to Campaign';
    if(str_contains($url,'tab=automatic'))return 'Back to Automatic campaigns';
    return 'Back to Manual campaigns';
}
function campaign_admin_back_link(array $campaign): array
{
    $url=campaign_navigation_request_return();
    if(!$url) {
        $url=campaign_navigation_automatic($campaign)?'campaigns.php?tab=automatic':'campaigns.php?tab=manual';
        if(($campaign['campaign_type']??'')==='ride_notes' && !empty($campaign['event_id']))$url='ride_notes.php?event_id='.(int)$campaign['event_id'];
    }
    return [$url,campaign_navigation_back_label($url)];
}
function campaign_template_back_link(array $template): array
{
    $url=campaign_navigation_request_return()??(in_array((string)($template['template_key']??''),campaign_automated_template_keys(),true)?'campaigns.php?tab=automatic':'campaigns.php?view=templates');
    return [$url,campaign_navigation_back_label($url)];
}
function render_campaign_navigation(string $active,string $role,?array $back=null): void
{
    $tabs=['manual'=>['campaigns.php?tab=manual','Manual campaigns'],'automatic'=>['campaigns.php?tab=automatic','Automatic campaigns'],'templates'=>['campaigns.php?view=templates','Templates'],'history'=>['email.php','Email history']];
    if($role==='developer')$tabs['migration']=['migration_campaign.php','Account migration'];
    echo '<nav aria-label="Email campaigns" data-table-navigation><ul class="nav nav-tabs admin-tabs mb-4">';
    foreach($tabs as $key=>[$url,$label])echo '<li class="nav-item"><a class="nav-link'.($key===$active?' active':'').'"'.($key===$active?' aria-current="page"':'').' href="'.h($url).'">'.h($label).'</a></li>';
    echo '</ul></nav>';
    if($back)echo str_replace('<nav ', '<nav data-table-navigation ', render_back_navigation($back[0],$back[1],'Campaign navigation'));
}
