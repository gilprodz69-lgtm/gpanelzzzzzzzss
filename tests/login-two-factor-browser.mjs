import{chromium}from'playwright';
import{execFileSync}from'node:child_process';
import assert from'node:assert/strict';
const fixture=(action,id)=>execFileSync('.tools/php/php.exe',['tests/login-browser-fixture.php',action,...(id?[String(id)]:[])],{encoding:'utf8'});
const user=JSON.parse(fixture('create'));
const base=process.env.VPM_TEST_URL||'http://127.0.0.1:8087';
const browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];
page.on('pageerror',e=>errors.push(e.message));
try{
 await page.goto(process.env.VPM_TEST_URL||'http://127.0.0.1:8087');
 await page.locator('#login-form').waitFor();assert.equal(await page.locator('#login-code, .login-two-factor').count(),0);
 await page.getByLabel('E-mail',{exact:true}).fill(user.email);await page.getByLabel('Senha',{exact:true}).fill('wrong');
 await page.getByRole('button',{name:'Entrar no painel'}).click();await page.locator('#login-form .form-error.visible').waitFor();assert.equal(await page.locator('#two-factor-form').count(),0);
 await page.getByLabel('Senha',{exact:true}).fill(user.password);await page.getByRole('button',{name:'Entrar no painel'}).click();
 await page.locator('#two-factor-form').waitFor();assert.equal(await page.locator('input[type=password],#login-email').count(),0);
 const cookies=await page.context().cookies();assert(!cookies.some(c=>c.name==='vps_session'));assert(cookies.some(c=>c.name==='vps_login_challenge'&&c.httpOnly&&c.sameSite==='Strict'));
 assert.equal((await page.request.get(base+'/api/v1/auth/me')).status(),401);
 await page.screenshot({path:'test-results/login-second-factor.png'});
 await page.setViewportSize({width:390,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'test-results/login-second-factor-mobile.png'});
 await page.getByLabel('Código de autenticação').fill('000000');await page.getByRole('button',{name:'Verificar e entrar'}).click();await page.locator('#two-factor-form .form-error.visible').waitFor();
 await page.getByLabel('Código de autenticação').fill(fixture('code',user.id));await page.getByRole('button',{name:'Verificar e entrar'}).click();await page.locator('.sidebar').waitFor();
 const me=await(await page.request.get(base+'/api/v1/auth/me')).json();assert.equal(+me.user.id,user.id);
 assert.equal((await page.request.post(base+'/api/v1/auth/2fa',{data:{code:fixture('code',user.id)}})).status(),410);
 await page.request.post(base+'/api/v1/auth/logout',{headers:{'X-CSRF-Token':me.csrf},data:{}});
 fixture('disable',user.id);await page.reload();await page.locator('#login-form').waitFor();
 await page.getByLabel('E-mail',{exact:true}).fill(user.email);await page.getByLabel('Senha',{exact:true}).fill(user.password);await page.getByRole('button',{name:'Entrar no painel'}).click();await page.locator('.sidebar').waitFor();
 assert.equal(await page.locator('#two-factor-form').count(),0);assert.deepEqual(errors,[]);
 console.log('PASS real browser: no initial 2FA option, password-first challenge, no early session, bad code, success, replay rejected, direct login without 2FA, mobile layout');
}finally{await browser.close();fixture('cleanup',user.id);}
