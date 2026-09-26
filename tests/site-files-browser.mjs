import {chromium} from 'playwright';
import {readFile,mkdtemp,open,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1536,height:960}}),errors=[];
page.on('pageerror',e=>errors.push(e.message));
const sites=[{id:901,name:'loja.example.test',status:'active',config:{php_version:'8.3'}},{id:902,name:'blog.example.test',status:'active',config:{php_version:'8.2'}}];
await page.route('**/api/v1/websites',r=>r.fulfill({json:{data:sites}}));
await page.route('**/api/v1/websites/901',r=>r.fulfill({json:{data:sites[0]}}));
let requests=[],offset=0,declared=0,finished=false;
await page.route('**/api/v1/files/upload-chunk',async route=>{
 const h=route.request().headers(),bytes=route.request().postDataBuffer();
 assert.equal(h['content-type'],'application/octet-stream');assert(bytes.length<=8*1048576);
 const body={action:'upload_chunk',website_id:Number(h['x-upload-site']),offset:Number(h['x-upload-offset']),id:h['x-upload-id']};
 requests.push({action:body.action,site:body.website_id});assert.equal(body.website_id,902);assert.equal(body.offset,offset);offset+=bytes.length;
 await route.fulfill({json:{offset:body.offset+bytes.length}});
});
await page.route('**/api/v1/files',async route=>{
 const p=route.request().postDataJSON();requests.push({action:p.action,site:p.website_id,path:p.path});let result={};
 if(p.action==='list')result={data:[{name:'index.php',size:30,directory:false,mode:'640'}]};
 if(p.action==='read')result={content:Buffer.from('<?php echo "site";').toString('base64')};
 if(p.action==='upload_begin'){declared=p.size;result={id:'c'.repeat(32)};assert.equal(p.website_id,902);}
 if(p.action==='upload_chunk'){assert.equal(p.website_id,902);assert.equal(p.offset,offset);const bytes=Buffer.from(p.content,'base64');assert(bytes.length<=1048576);offset+=bytes.length;result={offset};}
 if(p.action==='upload_finish'){assert.equal(offset,declared);finished=true;}
 await route.fulfill({json:result});
});
const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();
await page.goto(process.env.VPM_TEST_URL||'http://127.0.0.1:8080');await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
await page.locator('nav [data-route=websites]').click();
await page.getByRole('link',{name:'Gerenciar arquivos',exact:true}).first().click();await page.locator('.fm-site-home h2').waitFor();
assert.equal(await page.locator('.fm-site-home h2').textContent(),sites[0].name);assert.equal(requests.length,0);
assert.equal(await page.locator('.fm-actions [data-fm=upload]').isDisabled(),true);
await page.screenshot({path:'test-results/site-folder-entry.png',fullPage:true});
await page.locator('.fm-site-home [data-fm=public]').click();await page.locator('[data-entry="index.php"]').waitFor();assert.equal(requests.at(-1).site,901);
assert.match(await page.locator('#fm-breadcrumbs').textContent(),/loja.example.test.*public_html/);
await page.locator('[data-entry="index.php"]').dblclick();await page.locator('.code-subheader').waitFor();assert.match(await page.locator('.code-subheader').textContent(),/loja.example.test.*public_html.*index.php/);
await page.getByRole('button',{name:'Fechar editor',exact:true}).click();await page.locator('dialog').waitFor({state:'hidden'});
await page.locator('#fm-site').selectOption('902');await page.getByRole('heading',{name:sites[1].name,exact:true}).waitFor();assert.equal(await page.locator('[data-entry]').count(),0);
const before=requests.length;await page.waitForTimeout(100);assert.equal(requests.length,before);
await page.locator('.fm-site-home [data-fm=public]').click();await page.locator('[data-entry="index.php"]').waitFor();assert.equal(requests.at(-1).site,902);
const temp=await mkdtemp(join(tmpdir(),'vpm-upload-'));
try{
 const file=join(temp,'large.zip'),handle=await open(file,'w');await handle.truncate(101*1024*1024+3);await handle.close();
 await page.locator('#fm-upload').setInputFiles(file);await page.getByText('Upload concluído.',{exact:true}).waitFor({timeout:90000});
 assert(finished);assert.equal(offset,101*1024*1024+3);assert.equal(requests.filter(r=>r.action==='upload_chunk').length,13);
}finally{await rm(temp,{recursive:true,force:true});}
await page.locator('#fm-breadcrumbs [data-fm=home]').click();await page.locator('#fm-breadcrumbs [data-fm=sites]').click();await page.locator('[data-site="901"]').waitFor();
for(const [width,height] of [[1366,768],[390,844]]){await page.setViewportSize({width,height});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);}
assert.deepEqual(errors,[]);await browser.close();console.log('PASS site actions, explicit site/public_html navigation, site switch isolation, editor identity and 101 MiB chunk upload');
