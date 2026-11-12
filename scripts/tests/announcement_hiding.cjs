const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
function run(type, allow, saved, days = type === 'manual' ? 7 : 14) {
    const file = type === 'manual' ? 'site.php' : 'views/super_announcement.php';
    const source = fs.readFileSync(file, 'utf8');
    const marker = type === 'manual' ? "const notice = document.getElementById('manualAnnouncement')" : "const notice = document.getElementById('superAnnouncement')";
    const start = source.lastIndexOf('<script>', source.indexOf(marker));
    let script = source.slice(start + 8, source.indexOf('</script>', start));
    script = script.replace(/<\?php[\s\S]*?\?>/g, php => php.includes('json_encode') ? '"token"' : php.includes('hide_days') ? String(days) : String(allow));
    const clicks = {}, events = {}, store = new Map();
    const key = `ildra_${type}_announcement_hidden_token`;
    if (saved) store.set(key, JSON.stringify(saved));
    const notice = {dataset: {noticeToken:'token'}, hidden:false, open:false, close(){this.open=false;}, showModal(){this.open=true;}, querySelector(selector){return selector === '.super-hide-device' && !allow ? null : {addEventListener(name, fn){clicks[selector]=fn;}};}, addEventListener(name,fn){events[name]=fn;}};
    const context = {document:{getElementById(id){return id === 'hideManualAnnouncement' ? (allow ? {addEventListener(name,fn){clicks.hide=fn;}} : null) : notice;},readyState:'complete'},localStorage:{getItem:k=>store.get(k)??null,setItem:(k,v)=>store.set(k,v)},window:{addEventListener:(name,fn)=>events[name]=fn},Date,JSON,Number};
    vm.runInNewContext(script,context);
    return {notice, clicks, store, key, events};
}
for (const type of ['manual','super']) {
    const hidden = {token:'token', expires:Date.now()+86400000};
    const isVisible = r => type === 'manual' ? !r.notice.hidden : r.notice.open;
    assert.ok(isVisible(run(type,false,hidden)), `${type}: Force Show must ignore saved hiding`);
    assert.ok(!isVisible(run(type,true,hidden)), `${type}: Allow Hide must honour saved hiding`);
    assert.ok(isVisible(run(type,true,{...hidden,expires:Date.now()-1})), `${type}: expired hiding`);
    assert.ok(isVisible(run(type,true,{...hidden,token:'another'})), `${type}: independent notice`);
    for (const days of [type === 'manual' ? 7 : 14, 21]) {
        const r=run(type,true,null,days), before=Date.now();
        (r.clicks.hide || r.clicks['.super-hide-device'])();
        const value=JSON.parse(r.store.get(r.key));
        assert.ok(Math.abs(value.expires-before-days*86400000)<1000, `${type}: duration`);
        assert.ok(!isVisible(r), `${type}: hide removes whole announcement`);
    }
    const r=run(type,true);
    r.events.storage({key:r.key,newValue:JSON.stringify(hidden)});
    assert.ok(!isVisible(r), `${type}: other tabs follow hide`);
    if (type === 'super') {
        const r=run(type,false);r.clicks['.super-close']();assert.equal(r.notice.open,false);
        assert.ok(isVisible(run(type,false)), 'Force Show returns on next page');
    }
}
console.log('Both announcement types: Force Show, Allow Hide, expiry, durations, independent notices and tab synchronisation passed.');
