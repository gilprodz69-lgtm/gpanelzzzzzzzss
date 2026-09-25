// Exercise form contracts, permission visibility, 2FA access and responsive login.
import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
try{
 const context=await browser.newContext({viewport:{width:1536,height:1024}}),page=await context.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();
 await page.goto('http://127.0.0.1:8080');await page.getByRole('button',{name:'Entrar no painel'}).waitFor();
 await page.getByLabel('Senha',{exact:true}).fill('visibility-test');await page.getByRole('button',{name:'Mostrar senha'}).click();assert.equal(await page.locator('#login-password').getAttribute('type'),'text');await page.getByRole('button',{name:'Ocultar senha'}).click();assert.equal(await page.locator('#login-password').getAttribute('type'),'password');
 await page.locator('.login-two-factor summary').click();assert(await page.getByLabel('Código de autenticação',{exact:true}).isVisible());await page.locator('.login-two-factor summary').click();
 for(const width of [1536,768,390,320]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'login overflow '+width);}
 await page.setViewportSize({width:1536,height:1024});await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
 let server={id:900,name:'Servidor de teste',address:'192.0.2.22',os:'Ubuntu 24.04',agent_url:'https://agent.example.test:9443',status:'online'},patch,settings={ns1:'',ns2:'',status:'not_configured'};
 await page.route('**/api/v1/servers',r=>r.fulfill({json:{data:[server]}}));
 await page.route('**/api/v1/servers/900',async r=>{if(r.request().method()==='PATCH'){patch=r.request().postDataJSON();server={...server,...patch};await r.fulfill({json:{message:'Servidor atualizado.'}});}else await r.fulfill({json:{data:server}});});
 await page.route('**/api/v1/settings',async r=>{if(r.request().method()==='POST'){settings={...r.request().postDataJSON().value,status:'pending_setup'};await r.fulfill({json:{message:'Configuração salva.'}});}else await r.fulfill({json:{data:[{name:'nameservers',value_json:JSON.stringify(settings)}]}});});
 await page.locator('[data-route=servers]').click();await page.getByRole('button',{name:'Editar',exact:true}).click();await page.getByLabel('Nome do servidor').fill('Servidor atualizado');assert.equal(await page.getByLabel('Novo segredo do agente (opcional)').inputValue(),'');await page.getByRole('button',{name:'Salvar servidor'}).click();await page.getByText('Servidor atualizado',{exact:true}).waitFor();assert.equal(patch.name,'Servidor atualizado');assert.equal(patch.agent_secret,'');assert.equal(patch.agent_url,server.agent_url);
 await page.locator('[data-route=settings]').click();await page.getByLabel('NS1',{exact:true}).fill('ns1.example.test');await page.getByLabel('NS2',{exact:true}).fill('ns2.example.test');await page.getByRole('button',{name:'Salvar nameservers'}).click();await page.getByText('Nameservers salvos.',{exact:true}).waitFor();assert.equal(settings.ns1,'ns1.example.test');
 await page.reload();await page.waitForFunction(()=>document.querySelector('input[name=ns1]')?.value==='ns1.example.test');assert((await page.locator('#nameservers-status').innerText()).includes('Ativação pendente'));
 const me=await (await context.request.get('http://127.0.0.1:8080/api/v1/auth/me')).json();
 await page.route('**/api/v1/auth/me',r=>r.fulfill({json:{...me,user:{...me.user,role:'CLIENT'},permissions:[...me.permissions,'servers.edit','settings.manage']}}));
 await page.goto('http://127.0.0.1:8080/#/servers');await page.reload();await page.getByRole('heading',{name:'Servidores VPS',exact:true}).waitFor();assert.equal(await page.locator('[data-edit-server]').count(),0);assert.equal(await page.locator('[data-grant]').count(),0);
 await page.locator('[data-route=settings]').click();await page.getByRole('heading',{name:'Configurações',exact:true}).waitFor();assert.equal(await page.locator('#nameservers-form').count(),0);
 assert.deepEqual(errors,[]);console.log('PASS login visibility/2FA/responsiveness, server edit, nameserver persistence and admin-only controls');
}finally{await browser.close();}
