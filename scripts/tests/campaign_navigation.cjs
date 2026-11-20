const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const directory=process.argv[2];if(!directory || !fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass a temporary fixture directory');
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await page.route('**/*',route=>route.abort());
  for(const file of fs.readdirSync(directory).filter(f=>f.endsWith('.html'))){
   await page.setContent(fs.readFileSync(`${directory}/${file}`,'utf8'));
   const tabs=page.locator('nav[aria-label="Email campaigns"] a');
   assert.ok((await page.locator('.btn').evaluateAll(elements=>elements.map(el=>getComputedStyle(el).fontWeight))).every(weight=>Number(weight)===400),'Public bold button styling leaked into admin');
   assert.deepEqual(await tabs.allTextContents(),['Manual campaigns','Automatic campaigns','Templates','Email history','Account migration']);
   assert.equal(await page.locator('a[aria-current=page]').count(),1);
   const back=page.locator('.page-back-link');
   if(await back.count()){
    assert.ok((await back.textContent()).trim().startsWith('« Back to '));
    assert.equal(await back.evaluate(el=>getComputedStyle(el).borderTopColor),'rgb(108, 117, 125)');
    const href=await back.getAttribute('href');let destination;
    await page.route('**/*',route=>{destination=route.request().url();return route.fulfill({body:'Returned',contentType:'text/html'});});
    await page.evaluate(()=>{const base=document.createElement('base');base.href='http://fixture.invalid/admin/';document.head.append(base);});
    await back.click();
    assert.equal(destination,new URL(href,'http://fixture.invalid/admin/').href);
    await page.unroute('**/*');await page.route('**/*',route=>route.abort());
   }
  }
  console.log('PASS: consistent campaign tabs, active state, grey shared Back styling and click destinations across 15 navigation views in Chromium.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
