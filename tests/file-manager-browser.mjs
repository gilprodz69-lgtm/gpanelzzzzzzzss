// Deterministic UI contract tests; privileged filesystem behavior is tested separately on Linux.
import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1536,height:1024}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();
await page.goto(process.env.VPM_TEST_URL||'http://127.0.0.1:8080');await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
let rows=[{name:'assets',directory:true,size:4096,modified:Math.floor(Date.now()/1000),mode:'2750'},{name:'index.php',directory:false,size:24,modified:Math.floor(Date.now()/1000),mode:'640'}],trash=[];
const calls=[];
await page.route('**/api/v1/websites',route=>route.fulfill({json:{data:[{id:800,name:'site.example.test',status:'active'}]}}));
await page.route('**/api/v1/files/upload-chunk',async route=>{
 const h=route.request().headers(),bytes=route.request().postDataBuffer();
 assert.equal(h['content-type'],'application/octet-stream');assert(bytes.length<=8*1048576);
 const body={action:'upload_chunk',website_id:Number(h['x-upload-site']),offset:Number(h['x-upload-offset']),id:h['x-upload-id']};
 calls.push(body);
 await route.fulfill({json:{offset:body.offset+bytes.length}});
});
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
await page.locator('[data-route=files]').click();await page.locator('[data-site="800"]').click();await page.locator('.fm-site-home [data-fm=public]').click();await page.locator('[data-entry="assets"]').waitFor();
await page.screenshot({path:'test-results/file-manager-desktop.png',fullPage:true});
await page.locator('[data-entry="assets"] [data-check]').check();await page.locator('[data-entry="index.php"] [data-check]').check();assert.equal(await page.locator('[data-entry].selected').count(),2);
await page.locator('.fm-sidebar [data-fm=invert]').click();assert.equal(await page.locator('[data-entry].selected').count(),0);
await page.locator('#fm-search').fill('index');await page.locator('#fm-select-all').check();assert.equal(await page.locator('[data-entry].selected').count(),1);await page.locator('#fm-search').fill('');
await page.locator('[data-entry="index.php"] [data-menu]').click();await page.locator('#fm-context [data-fm=rename]').waitFor();await page.keyboard.press('Escape');
await page.locator('[data-fm=grid]').click();assert(await page.locator('#fm-list').evaluate(e=>e.classList.contains('fm-grid')));await page.locator('[data-fm=list]').click();
await page.locator('[data-entry="assets"]').dblclick();await page.locator('#fm-breadcrumbs [data-path="assets"]').waitFor();
assert(calls.some(c=>c.action==='list'&&c.path==='assets'));
await page.locator('[data-fm=back]').click();await page.locator('[data-entry="index.php"]').waitFor();await page.locator('[data-fm=forward]').click();await page.locator('#fm-breadcrumbs [data-path="assets"]').waitFor();
await page.locator('#fm-breadcrumbs [data-fm=public]').click();
await page.locator('[data-entry="index.php"]').dblclick();
await page.locator('#modal.fm-editor-dialog').waitFor();
await page.locator('#file-code-surface .ace_text-input').focus();
await page.keyboard.press('Control+a');await page.keyboard.insertText('<?php echo "Updated";');
await page.keyboard.press('Control+s');await page.getByText('Salvo',{exact:true}).waitFor();
assert(calls.some(c=>c.action==='write'&&Buffer.from(c.content,'base64').toString()==='<?php echo "Updated";'));
await page.getByRole('button',{name:'Fechar editor',exact:true}).click();
await page.locator('#modal').waitFor({state:'hidden'});
await page.locator('[data-entry="index.php"]').click({button:'right'});await page.locator('#fm-context [data-fm=rename]').click();await page.locator('dialog input[name=target]').fill('main.php');await page.locator('dialog button[type=submit]').click();await page.locator('[data-entry="main.php"]').waitFor();
await page.locator('[data-entry="main.php"]').click();await page.locator('#fm-selection [data-fm=trash]').click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});await page.locator('.fm-sidebar [data-fm=bin]').click();await page.locator('[data-entry="'+ 'a'.repeat(32)+'"]').click();await page.locator('#fm-selection [data-fm=restore]').click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});await page.locator('.fm-sidebar [data-fm=public]').click();await page.locator('[data-entry="main.php"]').waitFor();
await page.locator('#fm-upload').setInputFiles({name:'binary.dat',mimeType:'application/octet-stream',buffer:Buffer.alloc(1500000,65)});await page.getByText('Upload concluído.',{exact:true}).waitFor();assert.equal(calls.filter(c=>c.action==='upload_chunk').length,1);assert(calls.some(c=>c.action==='upload_finish'));
for(const width of [1920,1024,768,390]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'overflow '+width);}
await page.screenshot({path:'test-results/file-manager-mobile.png',fullPage:true});
assert.deepEqual(errors,[]);await browser.close();console.log('PASS file manager navigation, editor, context rename, trash/restore, chunked upload and responsive layout');

