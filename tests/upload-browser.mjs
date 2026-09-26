import{chromium}from'playwright';
import assert from'node:assert/strict';
const browser=await chromium.launch(),page=await browser.newPage();
const blocks=[];let requests=0;
await page.route('**/api/v1/files/upload-chunk',async route=>{
 const h=route.request().headers(),body=route.request().postDataBuffer();
 assert.equal(h['x-upload-site'],'123');assert.equal(h['x-csrf-token'],'test-csrf');assert.equal(h['content-type'],'application/octet-stream');
 blocks.push({offset:+h['x-upload-offset'],body});requests++;
 if(requests===1)return route.fulfill({status:502,json:{error:'Simulated lost response after write'}});
 await route.fulfill({json:{offset:+h['x-upload-offset']+body.length}});
});
try{
 await page.goto(process.env.VPM_TEST_URL||'http://127.0.0.1:8080');
 const result=await page.evaluate(async()=>{
  const {uploadFile}=await import('/js/upload.js'),{setCsrf}=await import('/js/api.js');setCsrf('test-csrf');const calls=[],progress=[];
  const bytes=new Uint8Array(8*1024*1024+29);for(let i=0;i<bytes.length;i++)bytes[i]=i%251;
  await uploadFile({site:123,file:new File([bytes],'test.bin'),path:'test.bin',onProgress:(n)=>progress.push(n),request:async(action)=>{calls.push(action);return{id:'a'.repeat(32)};}});
  return{calls,progress:progress.length};
 });
 assert.deepEqual(result.calls,['upload_begin','upload_finish']);assert(result.progress>0);assert.equal(blocks.length,3);
 assert.equal(blocks[0].body.length,8*1024*1024);assert.deepEqual(blocks[0],blocks[1]);assert.equal(blocks[2].offset,8*1024*1024);assert.equal(blocks[2].body.length,29);
 console.log('PASS binary upload: 8 MiB blocks, exact retry after lost response, tail, CSRF and site headers, progress and completion');
}finally{await browser.close();}
