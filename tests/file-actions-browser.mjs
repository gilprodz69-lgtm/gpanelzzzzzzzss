import{chromium}from'playwright';import assert from'node:assert/strict';
const browser=await chromium.launch(),page=await browser.newPage(),calls=[];
const entries=['a.txt','b.txt','site.zip'].map(name=>({name,directory:false,size:12,mode:'640'}));
await page.route('**/api/v1/**',async r=>{const p=new URL(r.request().url()).pathname;let data={};
 if(p.endsWith('/auth/me'))data={user:{id:1,name:'Admin',role:'MASTER'},permissions:['files.manage','websites.view'],csrf:'test',quotas:{},servers:[],catalog:{}};
 else if(p.endsWith('/websites'))data={data:[{id:1,name:'site.test',status:'active',config:{}}]};
 else if(p.endsWith('/files')){const b=r.request().postDataJSON();calls.push(b);data={message:'OK',completed:['a.txt','b.txt']};if(b.action==='list')data={data:b.path==='folder'?[]:[...entries,{name:'folder',directory:true}]};if(b.action==='trash_list')data={data:[{...entries[0],id:'a'.repeat(32),path:'a.txt'}]};}
 await r.fulfill({json:data});});
try{
 await page.goto((process.env.VPM_TEST_URL||'http://127.0.0.1:8080')+'/#/files?site=1');await page.locator('.fm-site-home [data-fm=public]').click();
 for(const action of ['copy','move']){
  await page.locator('[data-entry="a.txt"] [data-check]').check();await page.locator('[data-entry="b.txt"] [data-check]').check();
  await page.locator(`.fm-actions [data-fm=${action}]`).click();await page.locator('#modal input[name=target]').fill('dest');await page.locator('#modal button[type=submit]').click();await page.locator('#modal').waitFor({state:'hidden'});
  const sent=calls.find(c=>c.action===action+'_many');assert.deepEqual(sent.paths,['a.txt','b.txt']);assert.equal(sent.target,'dest');
 }
 await page.locator('[data-entry="site.zip"]').click();await page.locator('.fm-actions [data-fm=unzip]').click();assert.equal(await page.locator('#modal input[name=target]').inputValue(),'');assert.equal(await page.locator('#modal input[name=overwrite]').isChecked(),false);
 await page.locator('[data-picker-folder=folder]').click();await page.locator('[data-picker-use]').click();assert.equal(await page.locator('#modal input[name=target]').inputValue(),'folder');await page.locator('[data-picker-root]').click();await page.locator('[data-picker-folder=folder]').waitFor();await page.locator('[data-picker-use]').click();assert.equal(await page.locator('#modal input[name=target]').inputValue(),'');await page.locator('#modal button[type=submit]').click();await page.locator('#modal').waitFor({state:'hidden'});assert(calls.some(c=>c.action==='unzip'&&c.target===''&&c.overwrite===false));
 for(const permanent of [false,true]){
  await page.locator('[data-entry="a.txt"]').click();await page.locator('.fm-actions [data-fm=trash]').click();const check=page.locator('#modal input[name=permanent]');assert.equal(await check.isChecked(),false);if(permanent)await check.check();
  await page.locator('#modal button[type=submit]').click();await page.locator('#modal').waitFor({state:'hidden'});assert(calls.some(c=>c.action===(permanent?'delete_tree':'trash')&&c.path==='a.txt'));
 }
 await page.locator('.fm-sidebar [data-fm=bin]').click();await page.locator('[data-entry="'+'a'.repeat(32)+'"]').click();await page.locator('.fm-actions [data-fm=trash]').click();await page.getByRole('heading',{name:'Excluir definitivamente'}).waitFor();await page.locator('#modal button[type=submit]').click();await page.locator('#modal').waitFor({state:'hidden'});
 assert(calls.some(c=>c.action==='purge'&&c.id==='a'.repeat(32)));assert(!calls.some(c=>c.action==='trash'&&c.id));
 console.log('PASS multi-file copy/move, current-folder extraction, permanent deletion checkbox and trash toolbar purge');
}finally{await browser.close();}
