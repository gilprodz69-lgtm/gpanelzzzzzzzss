import{chromium}from'playwright';import{readFile}from'node:fs/promises';import assert from'node:assert/strict';
const browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1440,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
let installations=[],sent=null;
await page.route('**/api/v1/plugins',r=>r.fulfill({json:{catalog:[{id:'wordpress',name:'WordPress',category:'Sites e blogs',description:'Crie sites, blogs e lojas com WordPress.',price:'Grátis',minimum_php:'8.3'}],installations}}));
await page.route('**/api/v1/websites',r=>r.fulfill({json:{data:[{id:88,name:'novo.exemplo.com',status:'active',config:{php_version:'8.3'}}]}}));
await page.route('**/api/v1/plugins/install',async r=>{sent=r.request().postDataJSON();installations=[{id:1,site:'novo.exemplo.com',website_id:88,status:'pending',created_at:1700000000}];await r.fulfill({json:{job_id:99,message:'Instalação iniciada.'}});});
try{
 await page.goto(process.env.VPM_TEST_URL||'http://127.0.0.1:8087');const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();
 await page.locator('[data-route=plugins]').click();await page.getByRole('heading',{name:'Loja de plugins'}).waitFor();await page.screenshot({path:'test-results/plugin-store.png'});
 await page.getByRole('button',{name:'Instalar WordPress'}).click();await page.locator('#modal').waitFor();assert.equal(await page.locator('#modal input').count(),2);assert.equal(await page.locator('#modal select').count(),1);
 await page.getByLabel('E-mail do administrador',{exact:true}).fill('dono@example.com');await page.getByLabel('Senha do WordPress',{exact:true}).fill('WordPressTest!28474');await page.screenshot({path:'test-results/wordpress-install-form.png'});
 await page.locator('#modal button[type=submit]').click();await page.locator('#modal').waitFor({state:'hidden'});await page.getByText('WordPress · novo.exemplo.com',{exact:true}).waitFor();
 assert.deepEqual(Object.keys(sent).sort(),['admin_email','admin_password','plugin','website_id']);assert.equal(sent.website_id,88);assert.equal(sent.admin_email,'dono@example.com');
 installations[0]={...installations[0],status:'completed',version:'6.x',url:'https://novo.exemplo.com'};await page.getByRole('button',{name:'Atualizar',exact:true}).click();await page.getByRole('link',{name:'Administrar',exact:true}).waitFor();assert.equal(await page.getByRole('link',{name:'Administrar',exact:true}).getAttribute('href'),'https://novo.exemplo.com/wp-admin/');
 await page.setViewportSize({width:390,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);console.log('PASS plugin menu/store, email/password-only WordPress form, installation progress, admin links and mobile layout');
}finally{await browser.close();}
