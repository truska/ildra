const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];
 if(!directory || !fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass the private /tmp fixture directory');
 const script=fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.bundle.min.js');
 const css=fs.readFileSync('/tmp/ildra-bootstrap-5.3.2.min.css');
 const browser=await chromium.launch({headless:true});
 try {
  for(const width of [1440,390]) {
   const context=await browser.newContext({viewport:{width,height:1000}});const errors=[];
   await context.route('**/*',route=>{
    const url=route.request().url();
    if(url.endsWith('/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'))return route.fulfill({contentType:'application/javascript',headers:{'Access-Control-Allow-Origin':'*'},body:script});
    if(url.endsWith('/bootstrap@5.3.2/dist/css/bootstrap.min.css'))return route.fulfill({contentType:'text/css',headers:{'Access-Control-Allow-Origin':'*'},body:css});
    return route.abort();
   });
   for(const view of ['onboarding','after-onboarding','default','people','horses','person-edit','horse-edit','unpaid']) {
    const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
    await page.setContent(fs.readFileSync(`${directory}/account-${view}.html`,'utf8'),{waitUntil:'load'});
    assert.equal(await page.locator('#people').count(),1);assert.equal(await page.locator('#horses').count(),1);
    assert.equal(await page.locator('.account-summary-actions a').count(),view==='unpaid'?0:1);
    if(view!=='unpaid')assert.equal(await page.locator('.account-summary-actions a').textContent(),'Admin');
    assert.equal(await page.locator('.account-summary-actions a[href*="view=shares"]').count(),0);
    if(view==='unpaid') {
     assert.ok(await page.locator('#people a[href*="/memberships"]').count());
     assert.equal(await page.locator('#horses .btn-logbook-action:visible').isEnabled(),true);
     assert.equal(await page.locator('#horses form').filter({has:page.locator('input[name=action][value=add_logbook]')}).first().locator('input[name=horse_id]').inputValue(),'1981001');
     assert.equal(await page.getByText('Recent Purchases',{exact:true}).count(),1);
    }else {
     assert.equal(await page.getByText('Recent Purchases',{exact:true}).count(),1);

    }
    if(view==='onboarding') {
     await page.waitForSelector('#onboardingAccountWelcomeModal.show');
     assert.equal(await page.locator('.modal.show').count(),1);
     assert.match(await page.locator('#onboardingAccountWelcomeModal .modal-body').textContent(),/Fixture onboarding account welcome/);
     await page.locator('#onboardingAccountWelcomeModal .btn-close').click();
     await page.waitForSelector('#onboardingAccountWelcomeModal.show',{state:'hidden'});
    }else assert.equal(await page.locator('#onboardingAccountWelcomeModal').count(),0);
    assert.equal(await page.locator('#people').getByText('People',{exact:true}).count(),1);
    assert.equal(await page.locator('#horses').getByText('Horses',{exact:true}).count(),1);
    assert.equal(await page.locator('#my-memberships').isVisible(),false);
    assert.equal(await page.locator('#horse-logbooks').isVisible(),false);
    if(view==='default' || view==='unpaid') {
     const membershipToggle=page.locator('[aria-controls=accountMembershipHistory]');
     const logbookToggle=page.locator('[aria-controls=accountLogbookHistory]');
     assert.equal(await membershipToggle.textContent(),'Show Memberships');assert.equal(await membershipToggle.getAttribute('aria-expanded'),'false');
     await membershipToggle.click();await page.waitForSelector('#accountMembershipHistory.show');
     await page.waitForFunction(()=>document.querySelector('[aria-controls=accountMembershipHistory]').textContent==='Hide Memberships');
     assert.equal(await page.locator('#my-memberships').isVisible(),true);
     assert.equal(await page.locator('#horse-logbooks').isVisible(),false);
     await logbookToggle.click();await page.waitForSelector('#accountLogbookHistory.show');
     assert.equal(await page.locator('#my-memberships').isVisible(),true);
     assert.equal(await page.locator('#horse-logbooks').isVisible(),true);
     await membershipToggle.click();await page.waitForSelector('#accountMembershipHistory.show',{state:'hidden'});
     await page.waitForFunction(()=>document.querySelector('[aria-controls=accountMembershipHistory]').textContent==='Show Memberships');
     assert.equal(await page.locator('#horse-logbooks').isVisible(),true);
     await page.waitForFunction(()=>document.querySelector('[aria-controls=accountLogbookHistory]').textContent==='Hide Logbooks');
     await logbookToggle.click();await page.waitForSelector('#accountLogbookHistory.show',{state:'hidden'});
     await page.waitForFunction(()=>document.querySelector('[aria-controls=accountLogbookHistory]').textContent==='Show Logbooks');
     assert.equal(await membershipToggle.getAttribute('aria-expanded'),'false');assert.equal(await logbookToggle.getAttribute('aria-expanded'),'false');
    }
    if(view==='person-edit') {
     await page.waitForSelector('#personEditorModal.show');
     assert.equal(await page.locator('#personEditorModal input[name=person_id]').inputValue(),'123');
     assert.equal(await page.locator('#personEditorModal input[name=first_name]').inputValue(),'Dashboard');
    }else if(view==='horse-edit') {
     await page.waitForSelector('#horseEditorModal.show');
     assert.equal(await page.locator('#horseEditorModal input[name=horse_id]').inputValue(),'1981001');
     assert.equal(await page.locator('#horseEditorModal input[name=name]').inputValue(),'Dashboard Horse');
    }else {
     await page.getByRole('button',{name:'Add Person',exact:true}).click();await page.waitForSelector('#personEditorModal.show');
     assert.equal(await page.locator('#personEditorModal input[name=action]').inputValue(),'save_person');
     await page.locator('#personEditorModal .btn-close').click();await page.waitForSelector('#personEditorModal.show',{state:'hidden'});
     await page.getByRole('button',{name:'Add Horse',exact:true}).click();await page.waitForSelector('#horseEditorModal.show');
     assert.equal(await page.locator('#horseEditorModal input[name=action]').inputValue(),'save_horse');
    }
    await page.close();
   }
   assert.deepEqual(errors,[],'Account script errors');await context.close();
  }
  console.log('PASS: three-section account layout, independent Memberships/Logbooks collapse controls, purchase links, onboarding welcome and People/Horse editors at desktop/mobile sizes with actual Bootstrap.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
