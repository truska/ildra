const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
    const directory = process.argv[2];
    if (!directory || !fs.realpathSync(directory).startsWith('/tmp/')) throw new Error('Pass a /tmp fixture directory');
    const bootstrap = fs.readFileSync('admin/_bootstrap.php', 'utf8');
    const modalStart = bootstrap.indexOf('<div class="modal fade" id="sharedConfirmModal"');
    const scriptStart = bootstrap.indexOf('<script>', modalStart);
    const modal = bootstrap.slice(modalStart, scriptStart);
    const script = bootstrap.slice(scriptStart + 8, bootstrap.indexOf('</script>', scriptStart));
    const stub = `window.bootstrap={Modal:{getOrCreateInstance(el){return {show(){el.hidden=false},hide(){el.hidden=true;el.dispatchEvent(new Event('hidden.bs.modal'))}}}}};document.addEventListener('click',e=>{if(e.target.matches('[data-bs-dismiss="modal"]'))bootstrap.Modal.getOrCreateInstance(document.getElementById('sharedConfirmModal')).hide()});`;
    const browser = await chromium.launch({headless:true});
    try {
        const page = await browser.newPage({viewport:{width:768,height:1024}});
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        for (const file of fs.readdirSync(directory).filter(file=>file.endsWith('.html'))) {
            await page.setContent(fs.readFileSync(directory+'/'+file,'utf8')+modal+`<script>${stub}</script><script>${script}</script><script>window.submissions=[];document.addEventListener('DOMContentLoaded',()=>document.addEventListener('submit',event=>{if(!event.defaultPrevented){event.preventDefault();submissions.push(Object.fromEntries(new FormData(event.target)))}}));</script>`);
            await page.locator('#sharedConfirmModal').evaluate(el=>el.hidden=true);
            const button=page.getByRole('button',{name:'Delete permanently',exact:true});
            assert.equal(await button.count(),1,file);
            assert.equal(await button.evaluate(button=>button.form.id),'delete-gallery-image-41-82');
            assert.equal(await page.locator('#unsaved-gallery').evaluate(form=>form.checkValidity()),false);
            await button.click();
            await page.locator('#sharedConfirmModal').waitFor({state:'visible'});
            assert.equal(await page.evaluate(()=>submissions.length),0,'Delete submitted before confirmation: '+file);
            assert.ok((await page.locator('#sharedConfirmMessage').textContent()).includes('all generated sizes'));
            await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();
            assert.equal(await page.evaluate(()=>submissions.length),0,'Cancel submitted: '+file);
            await button.click();
            await page.locator('#sharedConfirmButton').click();
            await page.waitForFunction(()=>submissions.length===1);
            const data=await page.evaluate(()=>submissions[0]);
            assert.equal(data.image_id,'82');assert.ok(data.csrf);assert.equal(data.event_id,'7');
            assert.equal(data.action,file==='event_edit.html'?'delete_event_image':'delete_image');
            assert.equal(data.title,undefined,'Unsaved parent changes submitted: '+file);
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: seven gallery editors, actual delete buttons/forms, confirmation and cancel, CSRF/context, and isolation from unsaved required parent fields.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1)});
