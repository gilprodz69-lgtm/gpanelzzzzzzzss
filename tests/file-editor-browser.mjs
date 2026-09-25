import {chromium} from 'playwright';
import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';

const browser=await chromium.launch({headless:true});
const context=await browser.newContext({viewport:{width:1920,height:956},permissions:['clipboard-read','clipboard-write']});
const page=await context.newPage();
const failures=[];
page.on('pageerror',error=>failures.push(error.message));
page.on('console',message=>{if(message.type()==='error')failures.push(message.text());});
await page.route('**/editor-test',route=>route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'"},body:'<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><link rel="stylesheet" href="/css/app.css"><link rel="stylesheet" href="/css/file-editor.css"></head><body><button id="opener">Abrir</button><dialog id="modal" aria-labelledby="modal-title"></dialog></body></html>'}));
try{
 await page.goto('http://127.0.0.1:8080/editor-test');
 await page.evaluate(async()=>{
  const {openFileEditor}=await import('/js/file-editor.js');
  window.sample='<?php\r\n// Configuração do site\r\nfunction processPayment($config) {\r\n    $message = "Olá, mundo!";\r\n    return json_encode($config);\r\n}\r\n'+Array.from({length:180},(_,i)=>`// Linha ${i+7}`).join('\r\n');
  window.writes=[];window.hold=false;window.fail=false;
  window.openEditor=async(content=window.sample,name='addfunds.php')=>openFileEditor({modal:document.querySelector('#modal'),name,path:'includes/'+name,content,save:async value=>{window.writes.push(value);if(window.hold)await new Promise(resolve=>window.finishSave=resolve);if(window.fail)throw new Error('Falha de gravação de teste');}});
  await window.openEditor();
 });
 const editor=()=>page.locator('#file-code-surface .ace_text-input');
 const value=()=>page.evaluate(()=>ace.edit('file-code-surface').getValue());
 await page.locator('.ace_keyword').first().waitFor();
 const rect=await page.locator('#modal').boundingBox();assert.deepEqual(rect,{x:0,y:0,width:1920,height:956});
 assert(await page.locator('.ace_fold-widget').count()>0);
 await mkdir('test-results',{recursive:true});
 await page.screenshot({path:'test-results/file-editor-fullscreen.png'});
 await page.getByRole('button',{name:'Aumentar fonte',exact:true}).click();assert.equal(await page.locator('output').textContent(),'15px');
 await page.getByRole('button',{name:'Diminuir fonte',exact:true}).click();
 // Real keyboard editing, indentation, undo and save; CRLF is preserved.
 await editor().focus();await page.keyboard.press('Control+End');await page.keyboard.press('Enter');await page.keyboard.press('Tab');await page.keyboard.insertText('// Ação');
 assert((await value()).endsWith('\r\n    // Ação'));
 await page.keyboard.press('Control+z');assert(!(await value()).endsWith('Ação'));
 await page.keyboard.press('Control+Shift+z');assert((await value()).endsWith('Ação'));
 // Repeated Escape must continue to guard unsaved edits.
 for(let i=0;i<2;i++){page.once('dialog',d=>d.dismiss());await page.keyboard.press('Escape');assert(await page.locator('#modal').evaluate(e=>e.open));}
 await page.keyboard.press('Control+s');await page.getByText('Salvo',{exact:true}).waitFor();
 assert.equal(await page.evaluate(()=>writes.length),1);assert((await page.evaluate(()=>writes[0])).endsWith('\r\n    // Ação'));
 // Search and replacement use the editor's selection and native undo history.
 await page.keyboard.press('Control+f');await page.locator('.ace_search').waitFor();
 await page.locator('.ace_search_field').first().fill('processPayment');await page.keyboard.press('Enter');
 assert.equal(await page.evaluate(()=>ace.edit('file-code-surface').getSelectedText()),'processPayment');
 await page.keyboard.press('Escape');assert(await page.locator('#modal').evaluate(e=>e.open));
 // Clipboard commands remain undoable and operate on the selected range only.
 await page.getByRole('button',{name:'Copiar seleção',exact:true}).click();
 assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),'processPayment');
 await page.getByRole('button',{name:'Recortar seleção',exact:true}).click();
 await page.waitForFunction(()=>!ace.edit('file-code-surface').getValue().includes('processPayment'));
 await editor().focus();await page.keyboard.press('Control+z');assert((await value()).includes('processPayment'));
 // Slow saves use a snapshot; newer keystrokes stay dirty and duplicate saves are ignored.
 await page.evaluate(()=>{window.hold=true;});
 await page.keyboard.press('Control+s');await page.waitForFunction(()=>!!window.finishSave);
 await page.keyboard.press('Control+s');assert.equal(await page.evaluate(()=>writes.length),2);
 await page.keyboard.press('Control+End');await page.keyboard.insertText(' // novo');
 await page.getByRole('button',{name:'Fechar editor',exact:true}).click();assert(await page.locator('#modal').evaluate(e=>e.open));
 await page.evaluate(()=>{window.hold=false;window.finishSave();});
 await page.getByText('Alterações não salvas',{exact:true}).waitFor();
 assert.equal(await page.locator('.code-dirty').isVisible(),true);
 await page.evaluate(()=>{window.fail=true;});await editor().focus();await page.keyboard.press('Control+s');
 await page.getByRole('alert').filter({hasText:'Falha de gravação de teste'}).waitFor();
 assert((await value()).endsWith(' // novo'));assert(await page.locator('.code-dirty').isVisible());
 await page.evaluate(()=>{window.fail=false;});await page.keyboard.press('Control+s');await page.getByText('Salvo',{exact:true}).waitFor();
 for(const width of [1024,390]){
  await page.setViewportSize({width,height:844});const box=await page.locator('#modal').boundingBox();assert.equal(box.width,width);assert.equal(box.height,844);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
 }
 await page.screenshot({path:'test-results/file-editor-mobile.png'});
 await page.getByRole('button',{name:'Fechar editor',exact:true}).click();await page.locator('#modal').waitFor({state:'hidden'});
 // Plain text, HTML, UTF-8 BOM and nested special-character filenames survive open/save.
 await page.evaluate(()=>window.openEditor('\ufeff<!doctype html>\r\n<h1>Olá & mundo</h1>','a&b.html'));
 assert.equal(await page.locator('#modal-title').textContent(),'a&b.html');
 await page.getByRole('button',{name:'Salvar arquivo (Ctrl+S)',exact:true}).click();await page.getByText('Salvo',{exact:true}).waitFor();
 assert.equal(await page.evaluate(()=>writes.at(-1)),'\ufeff<!doctype html>\r\n<h1>Olá & mundo</h1>');
 await page.getByRole('button',{name:'Fechar editor',exact:true}).click();await page.locator('#modal').waitFor({state:'hidden'});
 assert.deepEqual(failures,[]);
 console.log('PASS fullscreen editor: syntax, folding, indent, undo/redo, search, clipboard, guarded close, save races/errors, UTF-8/CRLF/BOM, responsive layout, strict CSP');
}finally{await browser.close();}
