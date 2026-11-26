const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async()=>{
    const directory=process.argv[2];
    if(!directory || !fs.realpathSync(directory).startsWith('/tmp/'))throw new Error('Pass the private temporary fixture directory');
    const browser=await chromium.launch({headless:true});
    try {
        const page=await browser.newPage({viewport:{width:390,height:844}});
        await page.route('**/*',route=>route.abort());
        for(const step of ['password','user','complete','complete-basket','welcome','protected-welcome','closed','anonymous-welcome','other-account-welcome']) {
            await page.setContent(fs.readFileSync(`${directory}/${step}.html`,'utf8'));
            assert.equal(await page.locator('h1').textContent(),'Complete your account setup');
            assert.ok((await page.locator('.btn').evaluateAll(elements=>elements.map(el=>getComputedStyle(el).fontWeight))).every(weight=>Number(weight)===400),'Migration buttons unexpectedly bold');
            if(['password','user'].includes(step)) {
                assert.equal(await page.locator('[aria-current=step]').count(),0);
                assert.equal(await page.locator('.setup-progress').count(),0);
                assert.equal(await page.getByRole('button',{name:'Continue',exact:true}).count(),1);
                assert.equal(await page.getByRole('button',{name:'Finish',exact:true}).count(),0);
                assert.equal(await page.locator('input[name=address],input[name=postcode],select[name=junior_or_senior]').count(),0);
                assert.equal(await page.locator('form input[name=action]').first().inputValue(),['password','user'].includes(step)?'account':step);
                assert.ok(await page.locator('form input[name=csrf]').first().inputValue());
            }
            if(['password','user'].includes(step)) {
                assert.equal(await page.locator('input[name=first_name]').count(),1);
                assert.equal(await page.locator('input[name=last_name]').count(),1);
                assert.equal(await page.locator('input[name=email]').getAttribute('readonly'),'');
                assert.equal(await page.locator('.setup-progress').getByText('User settings',{exact:true}).count(),0);
            }
            if(step==='password') {
                assert.equal(await page.locator('input[type=password]').count(),2);
                assert.equal(await page.locator('input[type=password][value=""]').count(),2);
                assert.equal(await page.locator('input[name=current_password]').count(),0);
                await page.locator('input[name=new_password]').focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.evaluate(()=>document.activeElement.name),'confirm_password');
                await page.keyboard.press('Shift+Tab');
                assert.equal(await page.evaluate(()=>document.activeElement.name),'new_password');
                for(const field of ['new_password','confirm_password']) {
                    const input=page.locator(`input[name=${field}]`);
                    const toggle=page.locator(`[aria-controls="migration_${field}"]`);
                    await input.fill('fixture reveal password');
                    await toggle.click();
                    assert.equal(await input.getAttribute('type'),'text');
                    assert.equal(await toggle.locator('.fa-eye-slash').count(),1);
                    assert.ok((await toggle.getAttribute('aria-label')).startsWith('Hide '));
                    assert.equal(await toggle.getAttribute('aria-pressed'),'true');
                    assert.equal(await input.inputValue(),'fixture reveal password');
                    await toggle.click();
                    assert.equal(await input.getAttribute('type'),'password');
                    assert.equal(await toggle.locator('.fa-eye').count(),1);
                    assert.ok((await toggle.getAttribute('aria-label')).startsWith('Show '));
                    assert.equal(await toggle.getAttribute('aria-pressed'),'false');
                }
            }
            if(step==='user') {
                assert.equal(await page.locator('input[name=email]').getAttribute('readonly'),'');
                assert.equal(await page.locator('input[type=checkbox]').count(),0);
            }
            if(step==='welcome' || step==='protected-welcome') {
                assert.match(await page.locator('main').textContent(),/Fixture welcome/);
                await page.getByRole('button',{name:'Close My Account',exact:true}).click();
                const modal=page.locator('#migration-close');
                assert.equal(await modal.getAttribute('open'),'');
                assert.match(await modal.textContent(),/Fixture closure explanation/);
                if(step==='welcome') {
                    assert.equal(await modal.locator('input[name=closure_confirmed][required]').count(),1);
                    assert.equal(await modal.locator('input[name=action]').inputValue(),'close_account');
                    assert.equal(await modal.locator('input[name=copy_revision]').inputValue(),'1');
                } else {
                    assert.equal(await modal.getByRole('button',{name:'Yes, close my account',exact:true}).count(),0);
                    assert.match(await modal.textContent(),/cannot be closed/);
                }
                await modal.getByRole('button',{name:'Back',exact:true}).click();
                assert.equal(await page.locator('#migration-close[open]').count(),0);
                assert.equal(await page.getByRole('button',{name:'Continue',exact:true}).count(),1);
            }
            if(step==='anonymous-welcome') {
                assert.equal(await page.getByRole('button',{name:'Continue',exact:true}).count(),1);
                assert.equal(await page.getByRole('link',{name:'Sign in',exact:true}).count(),0);
                await page.getByRole('button',{name:'Close My Account',exact:true}).click();
                assert.equal(await page.locator('#migration-close[open]').count(),1);
                assert.equal(await page.locator('#migration-close input[name=closure_confirmed]').count(),1);
            }
            if(step==='other-account-welcome') {
                assert.match(await page.locator('main').textContent(),/different account/);
                assert.equal(await page.getByRole('button',{name:'Continue',exact:true}).count(),0);
                assert.equal(await page.getByRole('link',{name:'Sign out',exact:true}).count(),1);
            }
            if(step==='closed') {
                assert.match(await page.locator('main').textContent(),/Your account is closed/);
                assert.equal(await page.locator('form').count(),0);
            }
            if(step==='complete' || step==='complete-basket') {
                assert.equal(await page.locator('dialog[open]').count(),0);
                assert.equal(await page.locator('main').getByRole('link',{name:'Close',exact:true}).getAttribute('href'),'/account');
                if(step==='complete-basket')assert.equal(await page.locator('main').getByRole('link',{name:'Checkout your basket',exact:true}).getAttribute('href'),'/checkout');
            }
        }
        console.log('PASS: simplified migration progress, password controls, invitation/closure protections and single-page Continue completion controls in Chromium.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
