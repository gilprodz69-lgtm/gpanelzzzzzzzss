import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
try{
 const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const now=Math.floor(Date.now()/1000),rows=[['failed',71],['active',72],['pending',73]].map(([status,id])=>({id,name:'ssl-'+status+'.example.test',server_id:1,owner_id:3,status,created_at:now,config:{website_id:8,email:'test@example.test'}}));
 const me={user:{id:1,name:'Administrador',email:'admin@example.test',tenant_id:1,role:'MASTER'},permissions:['ssl_certificates.view','ssl_certificates.create','ssl_certificates.delete'],csrf:'test-csrf',quotas:{},servers:[{id:1,name:'BR-SERVER'}]};let requests=0;
 await page.route('**/api/v1/**',async route=>{
  const path=new URL(route.request().url()).pathname.replace('/api/v1','');
  if(path==='/branding')return route.fulfill({json:{name:'VPS Manager',logo:null}});
  if(path==='/auth/me')return route.fulfill({json:me});
  if(path==='/ssl_certificates')return route.fulfill({json:{data:rows}});
  if(path==='/ssl_certificates/71/retry'){
   assert.equal(route.request().method(),'POST');assert.equal(route.request().headers()['x-csrf-token'],'test-csrf');requests++;
   if(requests===1)return route.fulfill({status:409,json:{error:'Já existe uma operação em andamento para este certificado.'}});
   rows[0].status='pending';return route.fulfill({json:{id:71,job_id:99,status:'pending',message:'Nova tentativa de emissão SSL adicionada à fila.'}});
  }
  if(path==='/notifications')return route.fulfill({json:{data:[]}});
  throw new Error('Unexpected request: '+path);
 });
 await page.goto('http://127.0.0.1:8082/#/ssl_certificates');
 await page.locator('[data-retry-ssl="71"]').waitFor();assert.equal(await page.locator('[data-retry-ssl]').count(),1);
 const row=page.locator('tr').filter({has:page.locator('[data-retry-ssl="71"]')});assert.equal(await row.locator('[data-delete="ssl_certificates:71"]').count(),1);
 await page.locator('[data-retry-ssl="71"]').click();await page.getByRole('button',{name:'Cancelar',exact:true}).click();assert.equal(requests,0);
 await page.locator('[data-retry-ssl="71"]').click();await page.locator('dialog button[type=submit]').click();await page.locator('dialog .form-error').filter({hasText:'Já existe'}).waitFor();assert(await page.locator('dialog button[type=submit]').isEnabled());
 await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});assert.equal(requests,2);assert.equal(await page.locator('[data-retry-ssl]').count(),0);
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
 assert.deepEqual(errors,[]);console.log('PASS SSL retry button, cancellation, server error, pending transition, CSRF header and mobile layout');
}finally{await browser.close();}
