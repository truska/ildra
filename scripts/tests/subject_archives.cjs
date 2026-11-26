const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];
 if(!directory || !fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass the private /tmp fixture directory');
 const source=fs.readFileSync('admin/_bootstrap.php','utf8');
 const a=source.indexOf('<div class="modal fade" id="sharedConfirmModal"'),b=source.indexOf('<script>',a);
 const modal=source.slice(a,b),script=source.slice(b+8,source.indexOf('</script>',b));
 const stub=`window.bootstrap={Modal:{getOrCreateInstance(el){return {show(){el.hidden=false},hide(){el.hidden=true;el.dispatchEvent(new Event('hidden.bs.modal'))}}}}};document.addEventListener('click',e=>{if(e.target.matches('[data-bs-dismiss="modal"]'))bootstrap.Modal.getOrCreateInstance(document.getElementById('sharedConfirmModal')).hide()});`;
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await page.route('**/*',route=>route.abort());
  for(const type of ['people','horses']) {
   await page.setContent(fs.readFileSync(`${directory}/archive-${type}.html`,'utf8')+modal+`<script>${stub}</script><script>${script}</script><script>window.submissions=[];document.addEventListener('DOMContentLoaded',()=>document.addEventListener('submit',event=>{if(!event.defaultPrevented){event.preventDefault();submissions.push(Object.fromEntries(new FormData(event.target,event.submitter)))}}));</script>`);
   await page.locator('#sharedConfirmModal').evaluate(el=>el.hidden=true);
   const form=page.locator('form').filter({has:page.locator('input[name=id][value="1980001"]')}).filter({has:page.locator('input[name=action][value=purge]')});
   const button=form.getByRole('button',{name:'Permanently Delete',exact:true});
   assert.equal(await button.isEnabled(),true);
   if(type==='people')assert.equal(await page.locator('form').filter({has:page.locator('input[name=id][value="123"]')}).getByRole('button',{name:'Permanently Delete',exact:true}).isDisabled(),true);
   await button.click();
   assert.match(await page.locator('#sharedConfirmMessage').textContent(),/cannot be undone/);
   assert.equal(await page.locator('#sharedConfirmButton').isDisabled(),true);
   await page.locator('#sharedConfirmText').fill('delete');
   assert.equal(await page.locator('#sharedConfirmButton').isDisabled(),true);
   await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();
   assert.equal(await page.evaluate(()=>submissions.length),0);
   await button.click();await page.locator('#sharedConfirmText').fill('DELETE');
   await page.locator('#sharedConfirmButton').click();await page.waitForFunction(()=>submissions.length===1);
   const data=await page.evaluate(()=>submissions[0]);
   assert.equal(data.tab,type);assert.equal(data.id,'1980001');assert.equal(data.action,'purge');assert.equal(data.delete_confirmation,'DELETE');assert.ok(data.csrf);
   const restore=page.locator('form').filter({has:page.locator('input[name=id][value="1980001"]')}).filter({has:page.locator('input[name=action][value=restore]')});
   await restore.getByRole('button',{name:'Restore',exact:true}).click();
   await page.locator('#sharedConfirmButton').click();await page.waitForFunction(()=>submissions.length===2);
   const restored=await page.evaluate(()=>submissions[1]);assert.equal(restored.tab,type);assert.equal(restored.action,'restore');assert.ok(restored.csrf);
  }
  console.log('PASS: both archive tabs render, protected deletion is disabled, Cancel and exact DELETE confirmation work, and restore/purge forms preserve type, target and CSRF.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
