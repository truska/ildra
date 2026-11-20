const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory||!fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass a /tmp fixture directory');
 const browser=await chromium.launch({headless:true});try{
  const page=await browser.newPage({viewport:{width:768,height:1024}});
  for(const [mode,phrase] of [['keep_users','RESET ONBOARDING DATA'],['replace_users','DELETE UNPROTECTED USERS']]){
   const fixture=fs.readFileSync(directory+'/'+mode+'.html','utf8');
   const capture=`<script>window.submissions=[];document.addEventListener('submit',event=>{event.preventDefault();submissions.push(Object.fromEntries(new FormData(event.target,event.submitter)))})</script>`;
   await page.setContent(fixture+capture);
   assert.equal(await page.locator('button[name=reset_mode]').count(),2);
   const dialog=page.locator('#onboarding-reset-confirmation');assert.equal(await dialog.evaluate(el=>el.open),true);
   const run=page.locator('#onboarding-reset-run');const field=page.locator('#onboarding-reset-phrase');const quiet=page.locator('[name=reset_quiet]');
   assert.equal(await run.isDisabled(),true);
   await quiet.check();for(const invalid of [phrase.toLowerCase(),' '+phrase,phrase+' ']){await field.fill(invalid);assert.equal(await run.isDisabled(),true);}
   await field.fill(phrase);assert.equal(await run.isEnabled(),true);await quiet.uncheck();assert.equal(await run.isDisabled(),true);
   await page.locator('#onboarding-reset-cancel').click();assert.equal(await dialog.evaluate(el=>el.open),false);assert.equal(await page.evaluate(()=>submissions.length),0);
   await page.locator(`button[name=reset_mode][value=${mode}]`).click();let data=await page.evaluate(()=>submissions[0]);assert.equal(data.action,'preview_onboarding_reset');assert.equal(data.reset_mode,mode);assert.ok(data.csrf);
   await page.setContent(fixture+capture);await page.locator('#onboarding-reset-phrase').fill(phrase);await page.locator('[name=reset_quiet]').check();await page.locator('#onboarding-reset-run').click();
   data=await page.evaluate(()=>submissions[0]);assert.equal(data.action,'run_onboarding_reset');assert.equal(data.reset_confirmation,phrase);assert.equal(data.preview_token,'synthetic-preview-token');assert.ok(data.csrf);assert.equal(data.tab,'settings');
  }
  console.log('PASS: two reset choices, preview-only first clicks, cancel, exact typed confirmation, stopped-testing checkbox and CSRF/preview-token final submissions. No reset request sent.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
