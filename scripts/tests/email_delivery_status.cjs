const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
    const directory=fs.realpathSync(process.argv[2]||'');
    if(!directory.startsWith('/tmp/'))throw new Error('Pass the private temporary fixture directory');
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage({viewport:{width:390,height:844}});
        for(const state of ['accepted','partial','delivered']){
            await page.setContent(fs.readFileSync(`${directory}/${state}.html`,'utf8'));
            assert.equal(await page.getByRole('heading',{name:'Delivery status',exact:true}).count(),1);
            assert.equal(await page.getByText('Accepted by Amazon SES',{exact:true}).count(),1);
            assert.match(await page.locator('body').textContent(),/Original recipient: original@example.test/);
            if(state==='accepted'){
                assert.equal(await page.getByText('Delivery not confirmed',{exact:true}).count(),1);
                assert.equal(await page.getByText('Delivered',{exact:true}).count(),0);
                assert.equal(await page.getByRole('table').count(),0);
            }else{
                assert.equal(await page.getByRole('table').count(),1);
                assert.equal(await page.getByRole('cell',{name:'actual@example.test',exact:true}).count(),1);
                if(state==='partial')assert.equal(await page.getByText('Mixed recipient status',{exact:true}).count(),1);
                else assert.equal(await page.getByRole('cell',{name:'copy@example.test',exact:true}).count(),1);
            }
        }
        console.log('PASS: delivery details distinguish acceptance, partial/full delivery and redirected recipients in Chromium.');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
