const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory||!fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass /tmp fixtures');
 for(const [page,kind] of [['award_edit','award'],['advertising','advertising'],['announcements','announcement'],['dev_task','task_screenshot']]){
  const source=fs.readFileSync('admin/'+page+'.php','utf8');assert.ok(source.includes('admin_handle_record_image_delete($pdo, \''+kind+'\''));assert.ok(source.includes('admin_record_image_delete_button(\''+kind+'\''));assert.ok(source.includes('admin_record_image_delete_form(\''+kind+'\''));
 }
 const source=fs.readFileSync('admin/_bootstrap.php','utf8');const a=source.indexOf('<div class="modal fade" id="sharedConfirmModal"'),b=source.indexOf('<script>',a);
 const modal=source.slice(a,b),script=source.slice(b+8,source.indexOf('</script>',b));
 const stub=`window.bootstrap={Modal:{getOrCreateInstance(el){return {show(){el.hidden=false},hide(){el.hidden=true;el.dispatchEvent(new Event('hidden.bs.modal'))}}}}};document.addEventListener('click',e=>{if(e.target.matches('[data-bs-dismiss="modal"]'))bootstrap.Modal.getOrCreateInstance(document.getElementById('sharedConfirmModal')).hide()});`;
 const browser=await chromium.launch({headless:true});try{
  const page=await browser.newPage();
  for(const kind of ['award','advertising','announcement','task_screenshot']){
   await page.setContent(fs.readFileSync(directory+'/'+kind+'.html','utf8')+modal+`<script>${stub}</script><script>${script}</script><script>window.submissions=[];document.addEventListener('DOMContentLoaded',()=>document.addEventListener('submit',e=>{if(!e.defaultPrevented){e.preventDefault();submissions.push(Object.fromEntries(new FormData(e.target)))}}));</script>`);
   await page.locator('#sharedConfirmModal').evaluate(el=>el.hidden=true);
   const button=page.getByRole('button',{name:'Remove image',exact:true});assert.equal(await button.textContent(),'');assert.ok((await button.getAttribute('class')).includes('btn-danger'));assert.equal(await button.locator('.fa-trash-can').count(),1);
   await button.click();assert.equal(await page.evaluate(()=>submissions.length),0);if(kind==='announcement')assert.ok((await page.locator('#sharedConfirmMessage').textContent()).includes('shared Library image will be kept'));
   await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();assert.equal(await page.evaluate(()=>submissions.length),0);
   await button.click();await page.locator('#sharedConfirmButton').click();await page.waitForFunction(()=>submissions.length===1);
   const data=await page.evaluate(()=>submissions[0]);assert.equal(data.action,'remove_record_image');assert.ok(data.csrf);assert.equal(data.title,undefined);
  }
  console.log('PASS: four image-owner editors, red white-bin controls, confirmation/cancel, Library wording, CSRF and isolated submissions.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
