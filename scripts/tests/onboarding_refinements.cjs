const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory||!fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass a private /tmp fixture directory');
 const browser=await chromium.launch({headless:true});try{
  const page=await browser.newPage();await page.route('**/*',route=>route.abort());
  await page.setContent(fs.readFileSync(directory+'/admin-settings.html','utf8'));
  assert.equal(await page.locator('select').count(),3);
  for(const [label,value] of [['Enable Horses page','1'],['Enable Shares page','1'],['Show Blank Pages','0']]){
   const control=page.getByLabel(label,{exact:true});assert.equal(await control.inputValue(),value);
   assert.deepEqual(await control.locator('option').allTextContents(),['Yes','No']);await control.selectOption(value==='1'?'0':'1');
  }
  const data=await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));assert.ok(data.csrf);assert.equal(data.onboarding_show_blank_pages,'1');assert.equal(data.onboarding_horses_enabled,'0');assert.equal(data.onboarding_shares_enabled,'0');
  await page.setContent(fs.readFileSync(directory+'/admin-password.html','utf8'));
  assert.equal(await page.locator('textarea').count(),2,'Both existing wording blocks must remain editable');
  assert.equal(await page.locator('a[href="onboarding.php?tab=user"]').count(),0,'Standalone User page must not remain in navigation');
  await page.setContent(fs.readFileSync(directory+'/password.html','utf8'));
  for(const field of ['first_name','last_name','email','new_password','confirm_password'])assert.equal(await page.locator(`[name=${field}]`).count(),1);
  assert.equal(await page.locator('input[name=action]').first().inputValue(),'account');
  assert.equal(await page.locator('input[name=current_password]').count(),0);
  assert.equal(await page.locator('input[name=email]').getAttribute('readonly'),'');
  await page.setContent(fs.readFileSync(directory+'/horses-none.html','utf8'));assert.match(await page.locator('main').textContent(),/No horses to onboard/);
  await page.setContent(fs.readFileSync(directory+'/shares.html','utf8'));assert.match(await page.locator('main').textContent(),/No shares to review/);
  console.log('PASS: three Yes/No settings with one shared blank switch, preserved wording editors, combined account form and explicit empty-stage messages.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
