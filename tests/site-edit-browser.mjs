import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1536,height:960}}),errors=[],requests=[];
page.on('pageerror',e=>errors.push(e.message));
let editable=true,reject=true,site={id:900,name:'191.252.212.254',status:'active',owner_id:1,server_id:1,config:{php_version:'8.2'}};
await page.route('**/api/v1/**',async route=>{
 const path=new URL(route.request().url()).pathname;let result={data:[]};
 if(path.endsWith('/auth/me'))result={user:{id:1,name:'Administrador',role:'MASTER'},permissions:['websites.view','files.manage',...(editable?['websites.edit']:[])],csrf:'test',quotas:{},servers:[{id:1,name:'Servidor'}],catalog:{}};
 else if(path.endsWith('/websites'))result={data:[site]};
 else if(path.endsWith('/websites/900')){
  if(route.request().method()==='PATCH'){
   requests.push(route.request().postDataJSON());
   if(reject){await route.fulfill({status:409,json:{error:'Domínio já utilizado.'}});return;}
   site={...site,status:'pending'};result={job_id:20,message:'Alteração enviada ao servidor.'};
  }else result={data:site};
 }
 await route.fulfill({json:result});
});
const url=(process.env.VPM_TEST_URL||'http://127.0.0.1:8080')+'/#/websites';
try{
 await page.goto(url);
 await page.getByRole('button',{name:'Editar site',exact:true}).click();
 await page.getByRole('heading',{name:'Editar site',exact:true}).waitFor();
 assert.equal(await page.getByLabel('Domínio principal ou IP',{exact:true}).inputValue(),site.name);
 assert.equal(await page.locator('select[name=php_version]').inputValue(),'8.2');
 assert.equal(await page.locator('select[name=php_version] option').count(),6);
 assert.equal(await page.locator('#modal [name=server_id], #modal [name=owner_id]').count(),0);
 await page.getByLabel('Domínio principal ou IP',{exact:true}).fill('meusite.example.com');
 await page.locator('select[name=php_version]').selectOption('8.4');
 await page.getByRole('button',{name:'Salvar',exact:true}).click();
 await page.locator('.form-error.visible').waitFor();
 assert.equal(await page.getByLabel('Domínio principal ou IP',{exact:true}).inputValue(),'meusite.example.com');
 reject=false;
 await page.screenshot({path:'test-results/edit-site.png'});
 await page.getByRole('button',{name:'Salvar',exact:true}).click();
 await page.locator('.data-table .badge.pending').waitFor();
 assert.deepEqual(requests[1],{domain:'meusite.example.com',php_version:'8.4'});
 assert.equal(await page.getByRole('button',{name:'Editar site',exact:true}).count(),0);
 site={...site,status:'active'};editable=false;await page.reload();
 await page.getByRole('button',{name:'Detalhes',exact:true}).waitFor();
 assert.equal(await page.getByRole('button',{name:'Editar site',exact:true}).count(),0);
 assert.deepEqual(errors,[]);
 console.log('PASS site edit prefilled form, six PHP versions, validation error, PATCH payload, pending-state and permission visibility');
}finally{await browser.close();}
