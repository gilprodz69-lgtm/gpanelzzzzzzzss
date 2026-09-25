import {chromium} from 'playwright';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1536,height:960}}),errors=[],submitted=[];
page.on('pageerror',e=>errors.push(e.message));
const sites=[{id:901,name:'example.test',status:'active'},{id:902,name:'192.0.2.10',status:'active'}];
const rows=[{id:10,name:'alias.test',status:'active',config:{type:'alias',domain:'example.test',website_id:901}},{id:11,name:'blog.example.test',status:'active',config:{type:'subdomain',domain:'example.test',website_id:901,document_root:'blog.example.test'}}];
await page.route('**/api/v1/**',async route=>{
 const path=new URL(route.request().url()).pathname;let result={data:[]};
 if(path.endsWith('/auth/me'))result={user:{id:5,name:'Cliente',role:'CLIENT'},permissions:['domains.view','domains.create','domains.delete','websites.view','files.manage','jobs.view'],csrf:'test',quotas:{},servers:[],catalog:{}};
 else if(path.endsWith('/websites'))result={data:sites};
 else if(path.endsWith('/domains/11/access'))result={...rows[1],dns:{type:'A',name:rows[1].name,value:'192.0.2.10'}};
 else if(path.endsWith('/domains')){
  if(route.request().method()==='POST'){submitted.push(route.request().postDataJSON());result={message:'Criado'};}
  else result={data:rows};
 }
 await route.fulfill({json:result});
});
try{
 await page.goto((process.env.VPM_TEST_URL||'http://127.0.0.1:8080')+'/#/domains');
 await page.getByRole('button',{name:'Criar subdomínio'}).click();
 await page.getByLabel('Nome do subdomínio',{exact:true}).fill('loja');
 assert.match(await page.locator('[data-domain-preview]').textContent(),/loja.example.test/);
 assert.equal(await page.getByLabel('Domínio completo',{exact:true}).isVisible(),false);
 assert.equal(await page.locator('select[name=parent_domain] option').count(),3);
 await page.getByRole('button',{name:'Criar',exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('#modal').open);
 assert.deepEqual(submitted[0],{website_id:901,type:'subdomain',prefix:'loja',parent_domain:'example.test'});
 await page.getByRole('button',{name:'Criar subdomínio'}).click();
 await page.getByLabel('Site de hospedagem').selectOption('902');
 assert.equal(await page.getByRole('button',{name:'Criar',exact:true}).isDisabled(),true);
 assert.match(await page.locator('[data-domain-preview]').textContent(),/Adicione primeiro um alias/);
 await page.getByLabel('Tipo',{exact:true}).selectOption('alias');
 assert.equal(await page.getByRole('button',{name:'Criar',exact:true}).isDisabled(),false);
 await page.getByLabel('Domínio completo',{exact:true}).fill('nova.test');
 await page.getByLabel('Tipo',{exact:true}).selectOption('redirect');
 await page.getByLabel('Domínio de destino',{exact:true}).fill('destino.test');
 await page.getByRole('button',{name:'Criar',exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('#modal').open);
 assert.deepEqual(submitted[1],{website_id:902,type:'redirect',domain:'nova.test',target:'destino.test'});
 assert.equal(await page.getByRole('columnheader',{name:'Servidor',exact:true}).count(),0);
 await page.locator('[data-domain-details="11"]').click();
 await page.getByRole('heading',{name:'Detalhes do domínio / DNS',exact:true}).waitFor();
 assert.match(await page.locator('#modal').textContent(),/192\.0\.2\.10/);
 assert.match(await page.locator('#modal').textContent(),/public_html\/blog.example.test/);
 await page.screenshot({path:'test-results/domain-dns.png'});
 await page.getByRole('button',{name:'Fechar',exact:true}).click();
 assert.equal(await page.locator('a[href="#/files?site=901"]').count(),2);
 await page.screenshot({path:'test-results/domains.png'});
 await page.getByRole('button',{name:'Criar subdomínio'}).click();
 await page.getByRole('heading',{name:'Criar subdomínio',exact:true}).waitFor();
 await page.screenshot({path:'test-results/create-subdomain.png'});
 await page.setViewportSize({width:390,height:844});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 assert.deepEqual(errors,[]);
 console.log('PASS domain list/actions, subdomain preview/payload, IP hosting guidance, redirects, DNS details, client visibility and mobile form');
}finally{await browser.close();}
