// Deterministic UI contract tests; privileged filesystem behavior is tested separately on Linux.
import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1536,height:1024}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();
await page.goto('http://127.0.0.1:8080');await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
let rows=[{name:'assets',directory:true,size:4096,modified:Math.floor(Date.now()/1000),mode:'2750'},{name:'index.php',directory:false,size:24,modified:Math.floor(Date.now()/1000),mode:'640'}],trash=[];
const calls=[];
await page.route('**/api/v1/websites',route=>route.fulfill({json:{data:[{id:800,name:'site.example.test',status:'active'}]}}));
await page.route('**/api/v1/files',async route=>{
 const body=route.request().postDataJSON();calls.push(body);let result={message:'OK'};
 if(body.action==='list')result={data:body.path?[]:rows};
 if(body.action==='trash_list')result={data:trash};
 if(body.action==='read')result={content:Buffer.from('<?php echo "Hello";').toString('base64')};
 if(body.action==='rename'){rows=rows.map(r=>r.name===body.path?{...r,name:body.target}:r);}
 if(body.action==='trash'){const row=rows.find(r=>r.name===body.path);trash.push({...row,id:'a'.repeat(32),path:body.path,deleted_at:Math.floor(Date.now()/1000)});rows=rows.filter(r=>r!==row);}
 if(body.action==='restore'){rows.push(trash.find(r=>r.id===body.id));trash=[];}
 if(body.action==='upload_begin')result={id:'b'.repeat(32)};
 await route.fulfill({json:result});
});
await page.locator('[data-route=files]').click();await page.locator('[data-entry="assets"]').waitFor();
await page.screenshot({path:'test-results/file-manager-desktop.png',fullPage:true});
await page.locator('[data-entry="assets"]').dblclick();await page.locator('#fm-breadcrumbs [data-path="assets"]').waitFor();
assert(calls.some(c=>c.action==='list'&&c.path==='assets'));
await page.locator('#fm-breadcrumbs [data-fm=home]').click();await page.locator('[data-entry="index.php"]').dblclick();await page.locator('textarea[name=content]').fill('<?php echo "Updated";');await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});
assert(calls.some(c=>c.action==='write'&&Buffer.from(c.content,'base64').toString().includes('Updated')));
await page.locator('[data-entry="index.php"]').click({button:'right'});await page.locator('#fm-context [data-fm=rename]').click();await page.locator('dialog input[name=target]').fill('main.php');await page.locator('dialog button[type=submit]').click();await page.locator('[data-entry="main.php"]').waitFor();
await page.locator('[data-entry="main.php"]').click();await page.locator('#fm-selection [data-fm=trash]').click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});await page.locator('.fm-sidebar [data-fm=bin]').click();await page.locator('[data-entry="'+ 'a'.repeat(32)+'"]').click();await page.locator('#fm-selection [data-fm=restore]').click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});await page.locator('.fm-sidebar [data-fm=home]').click();await page.locator('[data-entry="main.php"]').waitFor();
await page.locator('#fm-upload').setInputFiles({name:'binary.dat',mimeType:'application/octet-stream',buffer:Buffer.alloc(1500000,65)});await page.getByText('Upload concluído.',{exact:true}).waitFor();assert.equal(calls.filter(c=>c.action==='upload_chunk').length,2);assert(calls.some(c=>c.action==='upload_finish'));
for(const width of [1920,1024,768,390]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'overflow '+width);}
await page.screenshot({path:'test-results/file-manager-mobile.png',fullPage:true});
assert.deepEqual(errors,[]);await browser.close();console.log('PASS file manager navigation, editor, context rename, trash/restore, chunked upload and responsive layout');
