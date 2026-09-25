import {chromium,request} from 'playwright';
import {readFile,mkdir,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const base='http://127.0.0.1:8080';
const planName='Validação navegador '+Date.now();
const local=await readFile('storage/LOCAL-ACCESS.txt','utf8');
const password=local.match(/Senha: (.+)/)[1].trim();
await mkdir('test-results',{recursive:true});
const browser=await chromium.launch({headless:true});
const context=await browser.newContext({viewport:{width:1536,height:1024}});
const page=await context.newPage();
const errors=[];page.on('pageerror',e=>errors.push(e.message));
page.on('console',m=>{if(m.type()==='error'&&!m.text().includes('401'))errors.push(m.text());});
const results=[];
async function test(name,fn){try{await fn();results.push({name,ok:true});console.log('PASS '+name);}catch(e){results.push({name,ok:false,error:e.message});console.error('FAIL '+name+': '+e.message);}}
await test('login and dashboard',async()=>{
 await page.goto(base);await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();await page.screenshot({path:'test-results/dashboard-desktop.png',fullPage:true});
});
await test('CSRF rejection on authenticated mutation',async()=>{
 const response=await context.request.post(base+'/api/v1/plans',{data:{name:'Forged'}});assert.equal(response.status(),419);
});
await test('unauthenticated API rejected',async()=>{
 const anonymous=await request.newContext();const r=await anonymous.get(base+'/api/v1/users');assert.equal(r.status(),401);await anonymous.dispose();
});
await test('create plan persists through reload',async()=>{
 await page.locator('nav a[data-route=plans]').click();await page.getByRole('button',{name:'Criar Plano',exact:true}).click();await page.getByLabel('Nome do plano',{exact:true}).fill(planName);await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});await page.getByText(planName,{exact:true}).waitFor();await page.reload();await page.getByText(planName,{exact:true}).waitFor();
});
await test('table search filters results',async()=>{
 await page.locator('#table-search').fill('inexistente-999');assert.equal(await page.locator('tr[data-search-row]:visible').count(),0);await page.getByText('Nenhum resultado encontrado',{exact:true}).waitFor();await page.locator('#table-search').fill('');
});
await test('server form validates required values',async()=>{
 await page.locator('nav a[data-route=servers]').click();await page.getByRole('button',{name:'Adicionar Servidor',exact:true}).click();await page.locator('dialog button[type=submit]').click();assert.equal(await page.locator('dialog[open]').count(),1);await page.getByRole('button',{name:'Fechar',exact:true}).click();
});
await test('navigation modules render without client errors',async()=>{
 for(const route of ['users','resellers','clients','websites','domains','databases','ssl_certificates','ftp_accounts','backups','firewall_rules','docker_containers','cron_jobs','audit_logs','jobs','files','services','api','settings']){
  await page.locator(`nav a[data-route=${route}]`).click();await page.locator('#content h1').waitFor();assert.equal(await page.getByText('Não foi possível abrir este módulo',{exact:true}).count(),0,route);
 }
});
await test('command palette keyboard shortcut',async()=>{
 await page.keyboard.press('Control+k');await page.locator('#global-search').fill('Sites');await page.locator('dialog .search-results a').first().click();await page.getByRole('heading',{name:'Sites',exact:true}).waitFor();
});
await test('responsive layout at all requested widths',async()=>{
 await page.goto(base+'/#/dashboard');await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
 for(const[width,height]of[[1920,1080],[1440,900],[1366,768],[1024,768],[768,1024],[390,844],[375,812]]){
  await page.setViewportSize({width,height});await page.waitForTimeout(150);
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);assert.equal(overflow,false,`horizontal overflow at ${width}`);
  if(width===390)await page.screenshot({path:'test-results/dashboard-mobile.png',fullPage:true});
 }
 await page.getByRole('button',{name:'Abrir menu',exact:true}).click();await page.locator('nav a[data-route=users]').click();await page.locator('#content h1').waitFor();assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await page.screenshot({path:'test-results/users-mobile.png',fullPage:true});
});
await test('dark mode toggle',async()=>{
 await page.getByRole('button',{name:'Alternar tema'}).click();assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');await page.screenshot({path:'test-results/dark-mobile.png',fullPage:true});
});
await test('no browser JavaScript errors',()=>assert.deepEqual(errors,[]));
await writeFile('test-results/browser-results.json',JSON.stringify({results,errors},null,2));
await browser.close();
console.log(`${results.filter(r=>r.ok).length} passed, ${results.filter(r=>!r.ok).length} failed`);
process.exitCode=results.some(r=>!r.ok)?1:0;
