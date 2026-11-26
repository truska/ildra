const {chromium}=require('playwright');const fs=require('node:fs');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<form><input name="postcode" value="bt-12 3ab!"><input name="company_postcode"><input name="people[4][postcode]"><input name="postal_code"><input name="component_value[7]" data-prefill="person.postcode"><input name="first_name" value="Mixed Case!"><button>Save</button></form>');
  await page.addScriptTag({content:fs.readFileSync('assets/postcodes.js','utf8')});
  assert.equal(await page.locator('[name=postcode]').inputValue(),'BT12 3AB');
  for(const name of ['postcode','company_postcode','people[4][postcode]','postal_code','component_value[7]']) {
   const field=page.locator(`input[name="${name}"]`);await field.fill('');await field.pressSequentially('bt12- 3ab!');
   assert.equal(await field.inputValue(),'BT12 3AB');assert.equal(await field.getAttribute('pattern'),'[A-Z0-9 ]*');
   await field.fill('  a65 -f4e2!  ');await field.blur();assert.equal(await field.inputValue(),'A65 F4E2');
  }
  await page.locator('[name=postal_code]').fill('00123');assert.equal(await page.locator('[name=postal_code]').inputValue(),'00123');
  await page.evaluate(()=>{const input=document.createElement('input');input.name='new_postcode';input.value='xx-é0';document.querySelector('form').append(input);});
  await page.waitForFunction(()=>document.querySelector('[name=new_postcode]').value==='XX0');
  await page.evaluate(()=>{document.querySelector('[name=postcode]').value='ab- 123!';document.querySelector('form').addEventListener('submit',e=>e.preventDefault());document.querySelector('form').requestSubmit();});
  assert.equal(await page.locator('[name=postcode]').inputValue(),'AB 123');
  assert.equal(await page.locator('[name=first_name]').inputValue(),'Mixed Case!');assert.deepEqual(errors,[]);
  console.log('PASS: typed/pasted uppercase postcodes, punctuation removal, spaces/zeros, company/nested/dynamic/prefill fields, submit normalization and unrelated inputs.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
