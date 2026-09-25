import {esc} from './api.js';
import {icon} from './icons.js';

const base='/vendor/ace/';
const scripts=new Map();
function loadScript(name){
 if(!scripts.has(name))scripts.set(name,new Promise((resolve,reject)=>{
  const script=document.createElement('script');script.src=base+name;
  script.onload=resolve;script.onerror=()=>{scripts.delete(name);script.remove();reject(new Error('Não foi possível carregar o editor. Atualize a página e tente novamente.'));};
  document.head.append(script);
 }));
 return scripts.get(name);
}
let cssReady;
async function loadEditor(name){
 if(!cssReady)cssReady=new Promise((resolve,reject)=>{
  const link=document.createElement('link');link.rel='stylesheet';link.href=base+'editor.css';
  link.onload=resolve;link.onerror=()=>{link.remove();cssReady=null;reject(new Error('Não foi possível carregar o estilo do editor.'));};
  document.head.append(link);
 });
 await Promise.all([cssReady,loadScript('ace.js')]);
 const ace=window.ace;ace.config.set('basePath',base);ace.config.set('useStrictCSP',true);
 const extension=name.toLowerCase().split('.').pop();
 const modes={php:'php',phtml:'php',html:'html',htm:'html',js:'javascript',mjs:'javascript',cjs:'javascript',jsx:'javascript',ts:'typescript',tsx:'typescript',css:'css',json:'json',xml:'xml',svg:'xml',sql:'sql',py:'python',sh:'sh',bash:'sh',yml:'yaml',yaml:'yaml',md:'markdown',ini:'ini',conf:'ini',env:'ini',htaccess:'sh'};
 const mode=modes[extension]||'text';
 await Promise.all(['theme-chrome.js','ext-searchbox.js',`mode-${mode}.js`].map(loadScript));
 return {ace,mode};
}

// The caller captures the website and path before loading, so saves cannot target another file.
export async function openFileEditor({modal,name,path,content,save,onClose}){
 const {ace,mode}=await loadEditor(name);
 if(modal.open)throw new Error('Feche a janela atual antes de abrir outro arquivo.');
 const previousFocus=document.activeElement;
 const button=(action,label,glyph)=>`<button type="button" data-code="${action}" aria-label="${label}" title="${label}">${icon(glyph)}</button>`;
 modal.classList.add('fm-editor-dialog');
 modal.innerHTML=`<section class="code-workspace" aria-label="Editor de código">
  <header class="code-header">${button('close','Fechar editor','close')}<h2 id="modal-title">${esc(name)}</h2><span class="code-dirty" aria-label="Alterações não salvas" hidden>●</span><span class="code-status" role="status" aria-live="polite"></span><div class="code-font">${button('larger','Aumentar fonte','plus')}<output aria-label="Tamanho da fonte">14px</output><button type="button" data-code="smaller" aria-label="Diminuir fonte" title="Diminuir fonte">−</button></div>${button('save','Salvar arquivo (Ctrl+S)','save')}</header>
  <div class="code-subheader"><nav aria-label="Caminho do arquivo">${icon('dashboard')}${['public_html',...path.split('/')].map(part=>`<span class="code-separator">›</span><span>${esc(part)}</span>`).join('')}</nav><div class="code-tools">${button('copy','Copiar seleção','copy')}${button('cut','Recortar seleção','cut')}${button('paste','Colar','clipboard')}<button type="button" data-code="menu" aria-label="Mais opções" title="Mais opções" aria-expanded="false" aria-controls="code-menu">${icon('more')}</button></div></div>
  <div id="code-menu" class="code-menu" hidden>${button('find','Localizar (Ctrl+F)','search')}${button('replace','Substituir (Ctrl+H)','edit')}${button('undo','Desfazer (Ctrl+Z)','back')}${button('redo','Refazer (Ctrl+Shift+Z)','arrow')}${button('wrap','Quebra de linha','list')}</div>
  <div class="code-error" role="alert" hidden></div><div id="file-code-surface" class="code-surface"></div>
 </section>`;
 modal.showModal();
 const $=selector=>modal.querySelector(selector),controller=new AbortController(),{signal}=controller;
 const menu=$('#code-menu'),status=$('.code-status'),error=$('.code-error');
 let editor,saving=false,closed=false,baseline=content,fontSize=14;
 try{
  editor=ace.edit($('#file-code-surface'),{theme:'ace/theme/chrome',useWorker:false,fontSize:14,fontFamily:'Consolas, "Liberation Mono", Menlo, monospace',showPrintMargin:false,showLineNumbers:true,showGutter:true,highlightActiveLine:true,displayIndentGuides:true,tabSize:4,useSoftTabs:true,wrap:false,scrollPastEnd:0,animatedScroll:false,enableBasicAutocompletion:false});
  editor.session.setMode(`ace/mode/${mode}`);
  editor.setValue(content,-1);editor.session.setNewLineMode(content.includes('\r\n')?'windows':'unix');
  editor.textInput.getElement().setAttribute('aria-label','Conteúdo do arquivo');
 }catch(e){modal.close();modal.classList.remove('fm-editor-dialog');editor?.destroy();throw e;}
 const dirty=()=>editor.getValue()!==baseline;
 const displayError=message=>{error.textContent=message;error.hidden=!message;editor.resize();};
 const update=()=>{$('.code-dirty').hidden=!dirty();status.textContent=saving?'Salvando…':dirty()?'Alterações não salvas':'';};
 const close=()=>{
  if(saving){displayError('Aguarde o término do salvamento antes de fechar.');return;}
  if(dirty()&&!window.confirm('Há alterações sem salvar. Fechar o editor e descartá-las?'))return;
  modal.close();
 };
 async function saveFile(){
  if(saving)return;
  const snapshot=editor.getValue();
  if(new TextEncoder().encode(snapshot).length>1048576){displayError('Limite de edição: 1 MB.');return;}
  saving=true;displayError('');update();$('[data-code=save]').disabled=true;
  try{await save(snapshot);baseline=snapshot;saving=false;update();if(!dirty())status.textContent='Salvo';}
  catch(e){saving=false;update();displayError(e.message||'Não foi possível salvar o arquivo.');}
  finally{$('[data-code=save]').disabled=false;}
 }
 async function clipboard(action){
  const value=editor.getValue(),range=editor.getSelectionRange().clone(),selected=editor.getSelectedText();
  if(action!=='paste'&&!selected){displayError('Selecione o texto que deseja copiar ou recortar.');return;}
  try{
   if(action==='paste'){
    if(!navigator.clipboard?.readText)throw new Error();
    const text=await navigator.clipboard.readText();
    if(closed)return;if(editor.getValue()!==value)throw new Error();
    editor.session.replace(range,text);
   }else{
    if(!navigator.clipboard?.writeText)throw new Error();
    await navigator.clipboard.writeText(selected);
    if(closed)return;
    if(action==='cut'){if(editor.getValue()!==value)throw new Error();editor.session.remove(range);}
   }
   displayError('');editor.focus();
  }catch{if(!closed)displayError('Use Ctrl+C, Ctrl+X ou Ctrl+V no editor. O navegador não permitiu esta operação pela barra.');}
 }
 $('.code-workspace').addEventListener('click',async event=>{
  const action=event.target.closest('[data-code]')?.dataset.code;if(!action)return;
  if(action==='menu'){menu.hidden=!menu.hidden;$('[data-code=menu]').setAttribute('aria-expanded',String(!menu.hidden));return;}
  menu.hidden=true;$('[data-code=menu]').setAttribute('aria-expanded','false');
  if(action==='close'){close();return;}
  if(action==='save'){await saveFile();return;}
  if(['copy','cut','paste'].includes(action)){await clipboard(action);return;}
  if(action==='larger'||action==='smaller'){fontSize=Math.max(10,Math.min(28,fontSize+(action==='larger'?1:-1)));editor.setFontSize(fontSize);$('output').textContent=`${fontSize}px`;$('[data-code=larger]').disabled=fontSize===28;$('[data-code=smaller]').disabled=fontSize===10;}
  if(action==='wrap'){const wrap=!editor.session.getUseWrapMode();editor.session.setUseWrapMode(wrap);$('[data-code=wrap]').setAttribute('aria-pressed',String(wrap));}
  if(['find','replace','undo','redo'].includes(action))editor.execCommand(action);
  editor.focus();
 },{signal});
 editor.session.on('change',update);
 editor.commands.addCommand({name:'saveFile',bindKey:{win:'Ctrl-S',mac:'Command-S'},exec:()=>void saveFile(),readOnly:true});
 modal.addEventListener('keydown',event=>{
  event.stopPropagation();
  if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();void saveFile();}
  if(event.key==='Escape'&&!menu.hidden){event.preventDefault();menu.hidden=true;$('[data-code=menu]').setAttribute('aria-expanded','false');editor.focus();}
 },{signal});
 modal.addEventListener('cancel',event=>{event.preventDefault();close();},{signal});
 window.addEventListener('beforeunload',event=>{if(dirty()||saving){event.preventDefault();event.returnValue='';}},{signal});
 const observer=new ResizeObserver(()=>editor.resize());observer.observe($('#file-code-surface'));
 modal.addEventListener('close',()=>{closed=true;controller.abort();observer.disconnect();editor.destroy();modal.classList.remove('fm-editor-dialog');modal.innerHTML='';previousFocus?.focus();Promise.resolve(onClose?.()).catch(()=>{});},{once:true});
 update();editor.resize();editor.focus();
}
