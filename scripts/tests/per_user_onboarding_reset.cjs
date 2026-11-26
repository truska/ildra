const {chromium}=require('playwright');const fs=require('node:fs');const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory||!fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass /tmp fixtures');
 const source=fs.readFileSync('admin/_bootstrap.php','utf8');const a=source.indexOf('<div class="modal fade" id="sharedConfirmModal"'),b=source.indexOf('<script>',a);
 const modal=source.slice(a,b),script=source.slice(b+8,source.indexOf('</script>',b));
 const stub=`window.bootstrap={Modal:{getOrCreateInstance(el){return {show(){el.hidden=false},hide(){el.hidden=true;el.dispatchEvent(new Event('hidden.bs.modal'))}}}}};document.addEventListener('click',e=>{if(e.target.matches('[data-bs-dismiss="modal"]'))bootstrap.Modal.getOrCreateInstance(document.getElementById('sharedConfirmModal')).hide()});`;
 const browser=await chromium.launch({headless:true});try{
  const page=await browser.newPage();await page.setContent(fs.readFileSync(directory+'/reset-user.html','utf8')+modal+`<script>${stub}</script><script>${script}</script><script>window.submissions=[];document.addEventListener('DOMContentLoaded',()=>document.addEventListener('submit',event=>{if(!event.defaultPrevented){event.preventDefault();submissions.push(Object.fromEntries(new FormData(event.target,event.submitter)))}}));</script>`);
  await page.locator('#sharedConfirmModal').evaluate(el=>el.hidden=true);
  const button=page.getByRole('button',{name:'Reset Onboarding',exact:true});assert.equal(await button.evaluate(el=>el.form.id),'reset-onboarding-2');
  await button.click();assert.match(await page.locator('#sharedConfirmMessage').textContent(),/fixture@example.invalid/);assert.match(await page.locator('#sharedConfirmMessage').textContent(),/next login/);assert.equal(await page.evaluate(()=>submissions.length),0);
  assert.equal(await page.locator('#sharedConfirmButton').isDisabled(),true);await page.locator('#sharedConfirmText').fill('reset onboarding');assert.equal(await page.locator('#sharedConfirmButton').isDisabled(),true);
  await page.locator('#sharedConfirmModal [data-bs-dismiss="modal"]').last().click();assert.equal(await page.evaluate(()=>submissions.length),0);
  await button.click();await page.locator('#sharedConfirmText').fill('RESET ONBOARDING');await page.locator('#sharedConfirmButton').click();await page.waitForFunction(()=>submissions.length===1);
  const data=await page.evaluate(()=>submissions[0]);assert.equal(data.action,'reset_onboarding');assert.equal(data.user_id,'2');assert.equal(data.person_id,'123');assert.equal(data.delete_confirmation,'RESET ONBOARDING');assert.ok(data.csrf);assert.equal(data.subject,undefined);
  // The immediate self-restart uses the same isolated, confirmed form and preserves its submitter value.
  await page.getByRole('button',{name:'Restart my onboarding now',exact:true}).click();
  assert.match(await page.locator('#sharedConfirmMessage').textContent(),/setup will open immediately/);
  await page.locator('#sharedConfirmText').fill('RESET ONBOARDING');
  await page.locator('#sharedConfirmButton').click();
  await page.waitForFunction(()=>submissions.length===2);
  const restart=await page.evaluate(()=>submissions[1]);
  assert.equal(restart.action,'reset_onboarding');assert.equal(restart.restart_now,'1');assert.equal(restart.user_id,'2');assert.equal(restart.delete_confirmation,'RESET ONBOARDING');assert.ok(restart.csrf);
  console.log('PASS: per-account Reset Onboarding confirmation, Cancel, exact typed phrase, target/CSRF and independence from invitation selection/draft forms.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
