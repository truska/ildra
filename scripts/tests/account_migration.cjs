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
        for(const step of ['password','user','person','horses','horses-none','horse-logbook','horse-keep','horse-archive','shares','finish','complete','complete-basket','welcome','protected-welcome','closed','anonymous-welcome','other-account-welcome']) {
            await page.setContent(fs.readFileSync(`${directory}/${step}.html`,'utf8'));
            assert.equal(await page.locator('h1').textContent(),'Complete your account setup');
            assert.ok((await page.locator('.btn').evaluateAll(elements=>elements.map(el=>getComputedStyle(el).fontWeight))).every(weight=>Number(weight)===400),'Migration buttons unexpectedly bold');
            if(['password','user','person','horses','horses-none','horse-logbook','horse-keep','horse-archive','shares','finish'].includes(step)) {
                assert.equal(await page.locator('[aria-current=step]').count(),1);
                assert.equal(await page.locator('form input[name=action]').first().inputValue(),['password','user'].includes(step)?'account':(step.startsWith('horse-')?'horse_choice':(step==='horses-none'?'horses':(step==='horses'?'horse_choice':step))));
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
            if(step==='person') {
                assert.equal(await page.locator('input[type=checkbox]').count(),1);
                assert.match(await page.locator('label[for=migration_address]').textContent(),/Full Postal Address/);
                assert.ok((await page.locator('label[for=migration_postcode]').getAttribute('class')).includes('fw-bold'));
                assert.equal(await page.getByLabel('Ride Reminders and Updates',{exact:true}).count(),1);
                const input=page.locator('input[name=phone]');
                assert.equal(await input.getAttribute('aria-invalid'),'true');
                assert.equal(await page.locator('#'+await input.getAttribute('aria-describedby')).textContent(),'Phone number is required.');
                assert.equal(await page.locator('input[name=first_name]').inputValue(),'Fixture');
                assert.equal(await page.locator('input[name=emergency_contact_phone][required]').count(),1);
                assert.equal(await page.locator('select[name=junior_or_senior][required]').count(),1);
                assert.equal(await page.getByRole('button',{name:'Back',exact:true}).count(),1);
            }
            if(step==='horses') {
                assert.equal(await page.getByRole('link',{name:'Buy a Logbook',exact:true}).getAttribute('href'),'/migrate.php?horse_id=400&horse_action=logbook');
                assert.equal(await page.getByRole('button',{name:'Logbook not Required',exact:true}).count(),1);
                assert.equal(await page.getByRole('link',{name:'Remove Horse',exact:true}).count(),1);
                assert.equal(await page.locator('input[name^="horses["]').count(),0);
                assert.match(await page.locator('main').textContent(),/last year/);
                assert.equal(await page.getByRole('button',{name:'Next',exact:true}).count(),1);
                await page.getByRole('button',{name:'Change Horse’s name',exact:true}).click();
                const rename=page.getByRole('dialog',{name:'Change horse’s name'});
                assert.equal(await rename.isVisible(),true);
                assert.equal(await rename.getByLabel('Horse name').inputValue(),'Fixture Horse');
                await rename.getByRole('button',{name:'Cancel',exact:true}).click();
                assert.equal(await rename.isVisible(),false);
                await page.getByRole('button',{name:'Change Horse’s name',exact:true}).click();
                await page.keyboard.press('Escape');assert.equal(await rename.isVisible(),false);

            }
            if(step==='horse-logbook') {
                assert.equal(await page.locator('input[name="horses[400][name]"]').inputValue(),'Fixture Horse');
                assert.equal(await page.locator('input[name="horses[400][owner_name]"][required]').count(),1);
                const passport=page.locator('input[name="horses[400][passport_number]"]');
                assert.equal(await passport.getAttribute('aria-invalid'),'true');
                assert.equal(await page.locator('#'+await passport.getAttribute('aria-describedby')).textContent(),'Passport number is required.');
                assert.equal(await page.getByRole('button',{name:'Add logbook to basket and return to horses',exact:true}).count(),1);
                assert.match(await page.locator('main').textContent(),/£7.50/);
            }
            if(step==='horse-keep') {
                assert.equal(await page.locator('input[name=name]').inputValue(),'Fixture Horse');
                assert.equal(await page.locator('input:not([type=hidden]),select,textarea').count(),1);
                assert.equal(await page.getByRole('button',{name:'Confirm name and return to horses',exact:true}).count(),1);
            }
            if(step==='horse-archive') {
                assert.equal(await page.locator('input[name=archive_confirmed][required]').count(),1);
                assert.equal(await page.locator('input[name=archive_confirmed]').getAttribute('aria-invalid'),'true');
                assert.equal(await page.getByRole('button',{name:'Confirm removal',exact:true}).count(),1);
                assert.match(await page.locator('main').textContent(),/retain its existing history/);
            }
            if(step.startsWith('horse-')) {
                assert.equal(await page.getByRole('link',{name:'Back to horses',exact:true}).getAttribute('href'),'/migrate.php');
                assert.equal(await page.getByRole('button',{name:'Next',exact:true}).count(),0);
            }
            if(step==='horses-none') {
                assert.match(await page.locator('main').textContent(),/No horses to onboard/);
                assert.equal(await page.getByRole('button',{name:'Next',exact:true}).count(),1);
                assert.equal(await page.locator('input[name^="horses["]').count(),0);
            }
            if(step==='shares') {
                assert.match(await page.locator('main').textContent(),/To follow/);
                assert.equal(await page.getByRole('button',{name:'Next',exact:true}).count(),1);
                assert.equal(await page.locator('input:not([type=hidden]),select,textarea').count(),0);
            }
            if(step==='finish') assert.equal(await page.getByRole('button',{name:'Finish',exact:true}).count(),1);
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
            if(step==='person'){
                assert.match(await page.locator('#migration-membership-type').textContent(),/Fixture Senior — £35.00/);
                assert.match(await page.locator('#migration-membership-type').textContent(),/Fixture Junior — £20.00/);
                assert.equal(await page.getByRole('button',{name:'Add membership to basket',exact:true}).count(),1);
            }
            if(step==='closed') {
                assert.match(await page.locator('main').textContent(),/Your account is closed/);
                assert.equal(await page.locator('form').count(),0);
            }
            if(step==='complete' || step==='complete-basket') {
                assert.equal(await page.locator('dialog[open]').count(),1);
                if(step==='complete-basket')assert.equal(await page.locator('dialog').getByRole('link',{name:'Checkout your basket',exact:true}).getAttribute('href'),'/checkout');
                assert.match(await page.locator('dialog').textContent(),/Thank you for migrating/);
                assert.equal(await page.getByRole('link',{name:'Membership – Join Now',exact:true}).count(),0);
                if(step==='complete')assert.equal(await page.getByRole('link',{name:'Checkout your basket',exact:true}).count(),0);
                assert.equal(await page.locator('dialog').getByRole('link',{name:'Close',exact:true}).getAttribute('href'),'/account');
                assert.equal(await page.locator('main').getByRole('link',{name:'Close',exact:true}).getAttribute('href'),'/account');
                if(step==='complete-basket')assert.equal(await page.locator('main').getByRole('link',{name:'Checkout your basket',exact:true}).getAttribute('href'),'/checkout');
            }
        }
        console.log('PASS: rendered migration forms, password secrecy, preserved values, required fields, accessible warnings, progress, Back/Finish and completion modal in Chromium.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
