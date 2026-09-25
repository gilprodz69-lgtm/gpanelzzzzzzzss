import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1920,height:950}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const now=Math.floor(Date.now()/1000);
const servers=[1,2,3].map(id=>({id,name:'BR-SERVER '+id,address:'192.0.2.'+id,os:'Ubuntu 24.04',status:'online',metrics:{cpu:12,ram:25,disk:10,memory_total:8e9,memory_used:2e9,disk_total:160e9,disk_used:16e9,network_rx_bps:23000,network_tx_bps:400,created_at:now}}));
await page.route('**/api/v1/auth/me',async route=>{const response=await route.fetch();const data=await response.json();data.servers=servers;await route.fulfill({response,json:data});});
await page.route('**/api/v1/dashboard',route=>route.fulfill({json:{servers,counts:{users:2,websites:3,databases:1,ssl_certificates:1},activity:[],pending_jobs:1}}));
await page.route('**/api/v1/servers/*/metrics?*',route=>route.fulfill({json:{data:[{created_at:now-600,cpu:10,ram:22,disk:10},{created_at:now,cpu:12,ram:25,disk:10}]}}));
const password=(await readFile('storage/LOCAL-ACCESS.txt','utf8')).match(/Senha: (.+)/)[1].trim();
await page.goto('http://127.0.0.1:8080');await page.getByLabel('E-mail',{exact:true}).fill('admin@vpsmanager.local');await page.getByLabel('Senha',{exact:true}).fill(password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.getByRole('heading',{name:'Olá, Administrador!'}).waitFor();
for(const title of ['Últimos Sites Criados','Uso por Servidor','Atividades Recentes'])assert.equal(await page.getByRole('heading',{name:title,exact:true}).count(),0);
for(const [width,height] of [[1920,950],[1536,864],[1440,900],[1366,768],[1280,720],[1280,650],[1024,768]]) {
 await page.setViewportSize({width,height});await page.waitForTimeout(50);
 const size=await page.evaluate(()=>({w:document.documentElement.scrollWidth,h:document.documentElement.scrollHeight}));
 assert(size.w<=width+1&&size.h<=height+1,`Dashboard overflow ${width}x${height}: ${JSON.stringify(size)}`);
 for(const selector of ['.greeting','.stats-grid','.resources-panel','.overview-panel','.chart-panel']) {
  const box=await page.locator(selector).boundingBox();assert(box.y+box.height<=height,`${selector} clipped at ${width}`);
 }
 const bounds=await page.locator('.resources-panel').evaluate(e=>({bottom:e.getBoundingClientRect().bottom,captions:[...e.querySelectorAll('.gauge p')].map(p=>p.getBoundingClientRect().bottom)}));
 assert(bounds.captions.every(y=>y<=bounds.bottom),`Resource captions clipped at ${width}x${height}`);
 const chartBox=await page.locator('.chart-panel').boundingBox(),queueBox=await page.locator('.dashboard-page > .status-note').boundingBox();
 assert(queueBox.y>=chartBox.y+chartBox.height,`Queue overlaps chart at ${width}x${height}`);
}
await page.setViewportSize({width:1536,height:864});await page.screenshot({path:'test-results/dashboard-compact.png',fullPage:true});
await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
await page.setViewportSize({width:1366,height:768});
const users=(await page.evaluate(async()=>await(await fetch('/api/v1/users')).json())).data;
const owner=users.find(u=>u.email==='admin@vpsmanager.local').id;
await page.route('**/api/v1/websites',route=>route.fulfill({json:{data:[{id:801,name:'one.example.test',server_id:1,owner_id:owner,status:'active'},{id:802,name:'two.example.test',server_id:2,owner_id:owner,status:'active'}]}}));
const created=[];
await page.route('**/api/v1/cron_jobs',async route=>{if(route.request().method()==='POST'){created.push(route.request().postDataJSON());await route.fulfill({json:{id:1,status:'pending',message:'Operação adicionada à fila do agente.'}});}else await route.fulfill({json:{data:[]}});});
await page.locator('nav a[data-route=cron_jobs]').click();await page.locator('[data-create=cron_jobs]').click();await page.locator('[data-cron-preset]').waitFor();
const preset=page.locator('[data-cron-preset]'),schedule=page.locator('input[name=schedule]');
for(const value of ['* * * * *','*/5 * * * *','*/10 * * * *','*/15 * * * *','*/30 * * * *','0 * * * *','0 */2 * * *','0 */6 * * *','0 */12 * * *']){await preset.selectOption(value);assert.equal(await schedule.inputValue(),value);}
await preset.selectOption('daily');await page.locator('[data-cron-time]').fill('08:30');assert.equal(await schedule.inputValue(),'30 8 * * *');
await preset.selectOption('weekly');await page.locator('[data-cron-weekday]').selectOption('1');assert.equal(await schedule.inputValue(),'30 8 * * 1');
await preset.selectOption('monthly');await page.locator('[data-cron-day]').fill('31');assert.equal(await schedule.inputValue(),'30 8 31 * *');assert.match(await page.locator('[data-cron-preview]').textContent(),/ignorados/);
await page.locator('[data-cron-day]').fill('32');assert.equal(await page.locator('dialog form').evaluate(f=>f.checkValidity()),false);
await page.locator('[data-cron-day]').fill('15');
await preset.selectOption('custom');await schedule.fill('10 2 * * 7');assert.equal(await schedule.inputValue(),'10 2 * * 7');
await page.locator('select[name=website_id]').selectOption('802');assert.equal(await page.locator('select[name=server_id]').inputValue(),'2');
await page.locator('select[name=server_id]').selectOption('3');assert.equal(await page.locator('select[name=website_id]').inputValue(),'');
await page.locator('select[name=server_id]').selectOption('1');assert.equal(await page.locator('select[name=website_id]').inputValue(),'801');
await preset.selectOption('weekly');await page.getByLabel('Script PHP relativo ao site').fill('tasks/rotina.php');
await page.screenshot({path:'test-results/cron-presets.png',fullPage:true});
await page.locator('dialog button[type=submit]').click();await page.locator('dialog').waitFor({state:'hidden'});
assert.deepEqual(created,[{server_id:1,owner_id:Number(owner),website_id:801,schedule:'30 8 * * 1',path:'tasks/rotina.php'}]);
assert.deepEqual(errors,[]);await browser.close();
console.log('PASS compact dashboard on seven desktop sizes, unclipped captions, mobile width, all cron presets, site selection, custom schedule and create payload');
