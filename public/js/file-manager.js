import {api,esc,date} from './api.js';
import {icon} from './icons.js';
import {openFileEditor} from './file-editor.js';
const sizes=n=>n<1024?`${n} B`:n<1048576?`${(n/1024).toFixed(1)} KB`:`${(n/1048576).toFixed(1)} MB`;
const labels={edit:'Editar',rename:'Renomear',copy:'Copiar',move:'Mover',trash:'Mover para lixeira',download:'Baixar',info:'Informações',chmod:'Permissões',zip:'Compactar',unzip:'Extrair ZIP',restore:'Restaurar',purge:'Excluir definitivamente'};
const fileType=r=>r.directory?'Pasta':/\.(png|jpe?g|gif|webp|svg|ico)$/i.test(r.name)?'Imagem':/\.php$/i.test(r.name)?'Arquivo PHP':/\.zip$/i.test(r.name)?'Arquivo ZIP':/\.xml$/i.test(r.name)?'Arquivo XML':'Arquivo';
const fileColor=r=>r.directory?'folder':/\.php$/i.test(r.name)?'php':/\.(png|jpe?g|gif|webp|svg|ico)$/i.test(r.name)?'image':'document';
const glyphs={edit:'edit',rename:'edit',copy:'copy',move:'arrow',trash:'trash',download:'download',info:'info',chmod:'shield',zip:'backups',unzip:'backups',restore:'refresh',purge:'trash'};
export async function fileManagerPage(){
 const sites=(await api('/websites')).data.filter(s=>s.status==='active');
 const button=(action,label,glyph,cls='')=>`<button type="button" data-fm="${action}" class="${cls}">${icon(glyph)}<span>${label}</span></button>`;
 return `<div id="file-manager" class="file-workspace"><div class="page-heading fm-heading"><div><h1>Gerenciador de arquivos</h1><p>Gerencie, edite, faça upload e organize os arquivos do seu site de forma simples e segura.</p></div><div class="fm-site-picker"><div>${icon('websites')}<select id="fm-site" aria-label="Site"><option value="">Selecione um site</option>${sites.map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></div><span id="fm-current-path">/public_html</span></div></div><section class="file-manager"><header class="fm-header"><div class="fm-actions">${button('upload','Upload','upload','fm-primary')}${button('folder','Nova pasta','files')}${button('new','Novo arquivo','document')}${button('zip','Compactar','backups')}${button('unzip','Descompactar','backups')}${button('copy','Copiar','copy')}${button('move','Mover','arrow')}${button('trash','Excluir','trash','fm-danger')}${button('chmod','Permissões','shield')}</div><div class="fm-search-tools"><div class="fm-search-box">${icon('search')}<input id="fm-search" type="search" placeholder="Pesquisar arquivos…" aria-label="Pesquisar arquivos"></div><button data-fm="list" class="fm-view active" aria-label="Visualização em lista" aria-pressed="true">${icon('list')}</button><button data-fm="grid" class="fm-view" aria-label="Visualização em grade" aria-pressed="false">${icon('grid')}</button></div></header><div class="fm-layout"><aside class="fm-sidebar"><h2>Navegação</h2><div id="fm-navigation"><button data-fm="home" class="active">${icon('files')}public_html</button></div><div class="fm-sidebar-group"><h2>Favoritos</h2><div id="fm-favorites"></div>${button('favorite','Adicionar aos favoritos','star')}</div><div class="fm-sidebar-group"><h2>Ações rápidas</h2>${button('select','Selecionar tudo','select')}${button('invert','Inverter seleção','invert')}${button('directory-info','Info do diretório','info')}${button('refresh','Atualizar','refresh')}${button('edit','Editor de texto','edit')}${button('search','Encontrar arquivos','search')}${button('bin','Lixeira','trash')}${button('download','Baixar seleção','download')}</div><div class="fm-storage"><span id="fm-account">Meus arquivos</span><p id="fm-usage">Selecione um site</p></div></aside><div class="fm-main"><div class="fm-pathbar"><button data-fm="back" aria-label="Voltar" disabled>${icon('back')}</button><button data-fm="forward" aria-label="Avançar" disabled>${icon('arrow')}</button><div class="fm-path"><nav id="fm-breadcrumbs" aria-label="Caminho"><button data-fm="home">${icon('dashboard')}public_html</button></nav><span id="fm-count">0 itens</span><button data-fm="directory-info" aria-label="Informações do diretório">${icon('more')}</button></div></div><div class="fm-selection" id="fm-selection" hidden></div><div class="fm-progress" id="fm-progress" role="status" hidden></div><div id="fm-list"><div class="empty">${icon('files')}<strong>Selecione um site para começar</strong><p>Os arquivos ficam restritos ao site selecionado.</p></div></div><footer id="fm-footer"></footer></div></div></section><div class="fm-context" id="fm-context" role="menu" hidden></div><input type="file" multiple hidden id="fm-upload"></div>`;
}
export function bindFileManager({showModal,modal,formShell,bindForm,toast}){
 const el=document.querySelector('#file-manager');if(!el)return;
 let path='',rows=[],selected=new Set(),bin=false,landing=true,grid=false,sort='name',ascending=true,busy=false,sequence=0,rootFolders=[],visits=[],visitIndex=-1;
 const favorites=new Map();
 const $=s=>el.querySelector(s),join=name=>[path,name].filter(Boolean).join('/');
 const siteName=()=>$('#fm-site').value?$('#fm-site').selectedOptions[0].textContent:'';
 function toolbar(){for(const button of el.querySelectorAll('.fm-actions button'))button.disabled=landing;$('#fm-search').disabled=landing;}
 function drawLanding(){
  toolbar();$('#fm-context').hidden=true;$('#fm-selection').hidden=true;$('#fm-footer').textContent='';$('#fm-list').className='';
  $('#fm-account').textContent=siteName()||'Meus sites';$('#fm-usage').textContent=siteName()?'Abra public_html para acessar os arquivos.':'Escolha a hospedagem que deseja gerenciar.';
  $('#fm-current-path').textContent=siteName()?'/'+siteName():'Meus sites';
  $('#fm-breadcrumbs').innerHTML=`<button data-fm="sites">${icon('websites')}Meus sites</button>${siteName()?`<span>›</span><button data-fm="home">${esc(siteName())}</button>`:''}`;
  $('#fm-navigation').innerHTML=`<button data-fm="sites">${icon('websites')}Meus sites</button>${siteName()?`<button data-fm="public">${icon('files')}public_html</button>`:''}`;
  $('#fm-favorites').innerHTML='';
  const sites=[...$('#fm-site').options].filter(o=>o.value);
  $('#fm-count').textContent=siteName()?'1 pasta':`${sites.length} sites`;
  $('#fm-list').innerHTML=siteName()?`<section class="fm-site-home"><h2>${esc(siteName())}</h2><p>Você está na hospedagem deste site. Entre na pasta abaixo para gerenciar seus arquivos.</p><button class="fm-site-card" data-fm="public">${icon('files')}<span><strong>public_html</strong><small>Arquivos públicos de ${esc(siteName())}</small></span>${icon('arrow')}</button></section>`:`<section class="fm-site-home"><h2>Escolha um site</h2><p>Selecione a hospedagem antes de abrir os arquivos.</p><div class="fm-sites">${sites.map(o=>`<button class="fm-site-card" data-site="${o.value}">${icon('websites')}<span><strong>${esc(o.textContent)}</strong><small>Abrir hospedagem</small></span>${icon('arrow')}</button>`).join('')||'<p>Nenhum site ativo disponível.</p>'}</div></section>`;
  el.querySelector('[data-fm="back"]').disabled=visitIndex<=0;el.querySelector('[data-fm="forward"]').disabled=visitIndex>=visits.length-1;
 }
 async function chooseSite(id){
  if(busy)throw new Error('Aguarde a transferência atual.');
  ++sequence;$('#fm-site').value=String(id);path='';bin=false;landing=true;rootFolders=[];visits=[];visitIndex=-1;rows=[];selected.clear();$('#fm-search').value='';
  history.replaceState(null,'',`#/files${$('#fm-site').value?'?site='+encodeURIComponent($('#fm-site').value):''}`);
  await refresh();
 }
 const request=(action,extra={})=>{const site=+$('#fm-site').value;if(!site)throw new Error('Selecione um site.');return api('/files',{method:'POST',body:{website_id:site,action,path,...extra}});};
 const keys=()=>rows.filter(r=>selected.has(bin?r.id:r.name));
 function visibleRows(){const term=$('#fm-search').value.toLocaleLowerCase();return rows.filter(r=>r.name.toLocaleLowerCase().includes(term)).sort((a,b)=>Number(b.directory)-Number(a.directory)||(ascending?1:-1)*(sort==='name'?a.name.localeCompare(b.name,'pt-BR'):sort==='type'?fileType(a).localeCompare(fileType(b)):(a[sort]||0)-(b[sort]||0)));}
 function draw(selectionOnly=false){
  if(landing){drawLanding();return;}toolbar();
  const visible=visibleRows(),term=$('#fm-search').value;
  $('#fm-current-path').textContent=siteName()+' / '+(bin?'Lixeira':'public_html'+(path?'/'+path:''));
  $('#fm-breadcrumbs').innerHTML=`<button data-fm="home">${icon('websites')}${esc(siteName())}</button><span>›</span><button data-fm="public">public_html</button>${bin?'<span>› Lixeira</span>':path.split('/').filter(Boolean).map((part,i,parts)=>`<span>›</span><button data-path="${esc(parts.slice(0,i+1).join('/'))}">${esc(part)}</button>`).join('')}`;
  $('#fm-navigation').innerHTML=`<button data-fm="home">${icon('websites')}${esc(siteName())}</button><button data-fm="public" class="${!path&&!bin?'active':''}">${icon('files')}public_html</button>${rootFolders.map(r=>`<button data-path="${esc(r.name)}" class="${!bin&&(path===r.name||path.startsWith(r.name+'/'))?'active':''}">${icon('files')}${esc(r.name)}</button>`).join('')}`;
  const saved=favorites.get($('#fm-site').value)||[];
  $('#fm-favorites').innerHTML=saved.map(p=>`<button data-path="${esc(p)}">${icon('star')}${esc(p.split('/').pop()||'public_html')}</button>`).join('');
  const actions=bin?['restore','purge']:['edit','rename','copy','move','chmod','zip','unzip','download','info','trash'];
  $('#fm-selection').hidden=!selected.size;
  $('#fm-selection').innerHTML=selected.size?`<span>${selected.size} selecionado(s)</span>${actions.map(a=>`<button data-fm="${a}" title="${labels[a]}" aria-label="${labels[a]}">${icon(glyphs[a])}</button>`).join('')}`:'';
  $('#fm-count').textContent=`${visible.length} itens`;
  $('#fm-footer').textContent=selected.size?`${selected.size} selecionado(s)`:'';
  el.querySelector('[data-fm="back"]').disabled=visitIndex<=0;
  el.querySelector('[data-fm="forward"]').disabled=visitIndex>=visits.length-1;
  for(const mode of ['list','grid']){const button=el.querySelector(`[data-fm="${mode}"]`);button.classList.toggle('active',grid===(mode==='grid'));button.setAttribute('aria-pressed',String(grid===(mode==='grid')));}
  if(selectionOnly===true){el.querySelectorAll('[data-entry]').forEach(row=>{const checked=selected.has(row.dataset.entry);row.classList.toggle('selected',checked);row.setAttribute('aria-selected',checked);row.querySelector('input[type=checkbox]').checked=checked;});const all=$('#fm-select-all');if(all){all.checked=visible.length>0&&visible.every(r=>selected.has(bin?r.id:r.name));all.indeterminate=visible.some(r=>selected.has(bin?r.id:r.name))&&!all.checked;}return;}
  $('#fm-list').className=grid?'fm-grid':'';
  const heading=(key,label)=>`<th><button data-sort="${key}">${label}<span class="fm-sort">${sort===key?(ascending?'↑':'↓'):'↕'}</span></button></th>`;
  $('#fm-list').innerHTML=visible.length?`<table class="fm-table"><thead><tr><th class="fm-check"><input id="fm-select-all" type="checkbox" aria-label="Selecionar todos os arquivos" data-fm="select" ${visible.every(r=>selected.has(bin?r.id:r.name))?'checked':''}></th>${heading('name','Nome')}${heading('size','Tamanho')}${heading('type','Tipo')}${heading('modified',bin?'Excluído em':'Modificado em')}<th>${bin?'Caminho original':'Permissões'}</th><th>Ações</th></tr></thead><tbody>${visible.map(r=>`<tr tabindex="0" data-entry="${esc(bin?r.id:r.name)}" class="${selected.has(bin?r.id:r.name)?'selected':''}" aria-selected="${selected.has(bin?r.id:r.name)}"><td class="fm-check"><input type="checkbox" data-check aria-label="Selecionar ${esc(r.name)}" ${selected.has(bin?r.id:r.name)?'checked':''}></td><td class="fm-name"><span class="fm-file-icon ${fileColor(r)}">${icon(r.directory?'files':'document')}</span><span>${esc(r.name)}</span></td><td>${r.directory?'—':sizes(r.size||0)}</td><td>${fileType(r)}</td><td>${date(bin?r.deleted_at:r.modified)}</td><td>${esc(bin?r.path:r.mode)}</td><td class="fm-row-actions"><button data-menu aria-label="Ações de ${esc(r.name)}">${icon('more')}</button></td></tr>`).join('')}</tbody></table>`:`<div class="empty">${icon(bin?'trash':'files')}<strong>${term?'Nenhum resultado':bin?'A lixeira está vazia':'Esta pasta está vazia'}</strong><p>${bin?'Itens excluídos aparecerão aqui.':'Arraste arquivos para esta área para enviar.'}</p></div>`;
  $('#fm-usage').textContent=`${rows.length} itens · ${sizes(rows.reduce((n,r)=>n+(r.directory?0:r.size||0),0))}`;
 }
 async function refresh(){const seq=++sequence;
  rows=[];selected.clear();$('#fm-selection').hidden=true;
  if(!landing){$('#fm-list').innerHTML='<div class="empty">Carregando arquivos de '+esc(siteName())+'…</div>';try{const result=await request(bin?'trash_list':'list');if(seq!==sequence||!el.isConnected)return;rows=result.data;if(!path&&!bin)rootFolders=rows.filter(r=>r.directory);}catch(e){if(seq===sequence&&el.isConnected){landing=true;drawLanding();}throw e;}}
  const current={path,bin,landing};if(!visits[visitIndex]||visits[visitIndex].path!==path||visits[visitIndex].bin!==bin||visits[visitIndex].landing!==landing){visits=visits.slice(0,visitIndex+1);visits.push(current);visitIndex=visits.length-1;}
  $('#fm-account').textContent=siteName();draw();}
 function single(){const entries=keys();if(entries.length!==1)throw new Error('Selecione um único item para esta operação.');return entries[0];}
 function prompt(title,body,handler,submit='Salvar'){showModal(title,formShell(body,submit));bindForm(modal.querySelector('form'),async f=>{await handler(Object.fromEntries(new FormData(f)));modal.close();await refresh();});}
 const input=(name,label,value='')=>`<label>${label}<input class="input" name="${name}" value="${esc(value)}" required></label>`;
 async function download(r){
  if(r.directory)throw new Error('Compacte a pasta antes de baixar.');const chunks=[];let offset=0,stamp=null,total;
  do{const result=await request('download',{path:join(r.name),offset});if(stamp!==null&&(result.modified!==stamp||result.size!==total))throw new Error('O arquivo mudou durante o download. Tente novamente.');stamp=result.modified;total=result.size;const bytes=Uint8Array.from(atob(result.content),c=>c.charCodeAt(0));chunks.push(bytes);offset+=bytes.length;if(!bytes.length&&offset<total)throw new Error('Download incompleto.');progress(`Baixando ${r.name}: ${sizes(offset)} / ${sizes(total)}`);}while(offset<total);
  const url=URL.createObjectURL(new Blob(chunks)),a=document.createElement('a');a.href=url;a.download=r.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
 }
 function progress(text){$('#fm-progress').hidden=!text;$('#fm-progress').textContent=text;}
 async function upload(list){if(bin||landing)throw new Error('Entre na pasta public_html do site para enviar arquivos.');if(!$('#fm-site').value)throw new Error('Selecione um site.');if(busy)throw new Error('Aguarde a transferência atual.');busy=true;$('#fm-site').disabled=true;
  try{for(const file of list){let id;try{({id}=await request('upload_begin',{path:join(file.name),size:file.size}));for(let offset=0;offset<file.size;offset+=1048576){const data=await file.slice(offset,offset+1048576).arrayBuffer();let text='';const bytes=new Uint8Array(data);for(let i=0;i<bytes.length;i+=8192)text+=String.fromCharCode(...bytes.subarray(i,i+8192));await request('upload_chunk',{id,offset,content:btoa(text)});progress(`Enviando ${file.name}: ${Math.round(Math.min(offset+1048576,file.size)/file.size*100)}%`);}await request('upload_finish',{id});}catch(e){if(id)await request('upload_cancel',{id}).catch(()=>{});throw e;}}toast('Upload concluído.');}finally{busy=false;$('#fm-site').disabled=false;progress('');await refresh();}
 }
 async function action(name){
  $('#fm-context').hidden=true;if(busy)throw new Error('Aguarde a transferência atual.');
  if(name==='grid'||name==='list'){grid=name==='grid';draw();return;}
  if(name==='select'||name==='invert'){const visible=visibleRows(),all=visible.every(r=>selected.has(bin?r.id:r.name));for(const r of visible){const key=bin?r.id:r.name;if(name==='invert'?selected.has(key):all)selected.delete(key);else selected.add(key);}draw();return;}
  if(name==='search'){$('#fm-search').focus();return;}
  if(name==='sites'){await chooseSite('');return;}
  if(name==='home'){path='';bin=false;landing=true;await refresh();return;}
  if(name==='public'){if(!$('#fm-site').value)throw new Error('Selecione um site.');path='';bin=false;landing=false;await refresh();return;}
  if(name==='back'||name==='forward'){const next=visitIndex+(name==='back'?-1:1);if(next<0||next>=visits.length)return;visitIndex=next;({path,bin,landing}=visits[next]);await refresh();return;}
  if(name==='refresh'){await refresh();return;}
  if(landing)throw new Error('Entre na pasta public_html do site primeiro.');
  if(name==='favorite'){if(!$('#fm-site').value||bin)throw new Error('Abra uma pasta de um site.');const site=$('#fm-site').value,list=favorites.get(site)||[];favorites.set(site,list.includes(path)?list.filter(p=>p!==path):[...list,path]);draw();toast('Favoritos atualizados para esta sessão.');return;}
  if(name==='directory-info'){if(bin)throw new Error('Abra uma pasta primeiro.');const info=await request('info');showModal('Informações do diretório',`<p><strong>/public_html${path?'/'+esc(path):''}</strong></p><p>Tamanho: ${sizes(info.size)}</p><p>Itens: ${info.items}</p><p>Permissões: ${esc(info.mode)}</p>`);return;}
  if(name==='home'){path='';bin=false;await refresh();return;}if(name==='bin'){bin=true;await refresh();return;}if(name==='refresh'){await refresh();return;}
  if(name==='upload'){$('#fm-upload').click();return;}
  if(['new','folder'].includes(name)){if(bin)throw new Error('Abra uma pasta primeiro.');prompt(name==='new'?'Novo arquivo':'Nova pasta',input('name','Nome'),async v=>{if(!v.name||/[\\/]/.test(v.name)||['.','..'].includes(v.name))throw new Error('Nome inválido.');await request(name==='new'?'upload_begin':'mkdir',name==='new'?{path:join(v.name),size:0}:{path:join(v.name)}).then(async r=>{if(name==='new')await request('upload_finish',{id:r.id});});},'Criar');return;}
  if(['trash','restore','purge'].includes(name)){if(!selected.size)throw new Error('Selecione pelo menos um item.');const items=keys();prompt(labels[name],`<p>${name==='purge'?'Excluir definitivamente':'Confirmar operação em'} ${items.length} item(ns)?</p>`,async()=>{for(const r of items)await request(name,bin?{id:r.id}:{path:join(r.name)});},'Confirmar');return;}
  if(name==='download'){if(!selected.size)throw new Error('Selecione arquivos para baixar.');busy=true;try{for(const r of keys())await download(r);}finally{busy=false;progress('');}return;}
  const r=single(),source=join(r.name);
  if(name==='open'){if(r.directory){path=source;await refresh();return;}name='edit';}
  if(name==='edit'){
   if(r.directory)throw new Error('Selecione um arquivo de texto.');
   if(bin)throw new Error('Restaure o arquivo antes de editar.');
   const websiteId=+$('#fm-site').value;
   busy=true;
   try{
    const result=await request('read',{path:source});let content;
    try{const bytes=Uint8Array.from(atob(result.content),c=>c.charCodeAt(0));content=new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(bytes);if(content.includes('\0'))throw new Error();}catch{throw new Error('Arquivo bin\u00e1rio: use Baixar.');}
    if(!el.isConnected)return;
    await openFileEditor({modal,name:r.name,path:source,siteName:siteName(),content,save:async value=>{
     const bytes=new TextEncoder().encode(value);let binary='';
     for(let i=0;i<bytes.length;i+=8192)binary+=String.fromCharCode(...bytes.subarray(i,i+8192));
     await api('/files',{method:'POST',body:{website_id:websiteId,action:'write',path:source,content:btoa(binary)}});
    },onClose:async()=>{if(el.isConnected)await refresh();}});
   }finally{busy=false;}
   return;
  }
  if(name==='info'){const info=await request('info',{path:source});showModal('Informações',`<p><strong>${esc(r.name)}</strong></p><p>Tamanho: ${sizes(info.size)}</p><p>Itens: ${info.items}</p><p>Permissões: ${esc(info.mode)}</p><p>Alterado: ${date(info.modified)}</p>`);return;}
  if(name==='chmod'){prompt('Permissões',`<label>Permissões<select class="input" name="mode">${['600','640','644','700','750','755'].map(m=>`<option ${m===r.mode?'selected':''}>${m}</option>`).join('')}</select></label>`,v=>request('chmod',{path:source,mode:v.mode}));return;}
  const targets={rename:source,copy:source+'.copy',move:source,zip:source+'.zip',unzip:source.replace(/\.zip$/i,'')+'-extraido'};
  if(Object.hasOwn(targets,name))prompt(labels[name],input('target','Caminho de destino relativo a public_html',targets[name]),v=>request(name==='move'?'rename':name,{path:source,target:v.target}));
 }
 const safe=fn=>async(...args)=>{try{await fn(...args);}catch(e){toast(e.message,true);}};
 el.addEventListener('click',safe(async e=>{const siteButton=e.target.closest('[data-site]');if(siteButton){await chooseSite(siteButton.dataset.site);return;}const menuButton=e.target.closest('[data-menu]');if(menuButton){openContext(menuButton.closest('[data-entry]'),menuButton.getBoundingClientRect().left,menuButton.getBoundingClientRect().bottom);return;}$('#fm-context').hidden=true;const button=e.target.closest('[data-fm]');if(button){await action(button.dataset.fm);return;}const crumb=e.target.closest('[data-path]');if(crumb){if(busy)return;path=crumb.dataset.path;bin=false;landing=false;await refresh();return;}const order=e.target.closest('[data-sort]');if(order){ascending=sort===order.dataset.sort?!ascending:true;sort=order.dataset.sort;draw();return;}const row=e.target.closest('[data-entry]');if(row){const key=row.dataset.entry;if(e.ctrlKey||e.metaKey||e.target.matches('[data-check]')){selected.has(key)?selected.delete(key):selected.add(key);}else selected=new Set([key]);draw(true);}}));
 el.addEventListener('dblclick',safe(async e=>{if(!bin&&e.target.closest('[data-entry]'))await action('open');}));
 function openContext(row,x,y){if(!selected.has(row.dataset.entry)){selected=new Set([row.dataset.entry]);draw(true);}const menu=$('#fm-context');menu.innerHTML=(bin?['restore','purge']:['edit','rename','copy','move','trash','download','info','chmod','zip','unzip']).map(a=>`<button data-fm="${a}" role="menuitem">${icon(glyphs[a])}${labels[a]}</button>`).join('');menu.hidden=false;menu.style.left=`${Math.max(8,Math.min(x,window.innerWidth-230))}px`;menu.style.top=`${Math.max(8,Math.min(y,window.innerHeight-menu.offsetHeight-8))}px`;}
 el.addEventListener('contextmenu',e=>{const row=e.target.closest('[data-entry]');if(!row)return;e.preventDefault();openContext(row,e.clientX,e.clientY);});
 el.addEventListener('keydown',safe(async e=>{if(e.key==='Escape')$('#fm-context').hidden=true;if(e.target.closest('[data-entry]')&&e.key==='Enter'&&!bin){e.preventDefault();await action('open');}}));
 $('#fm-site').addEventListener('change',safe(async()=>chooseSite($('#fm-site').value)));$('#fm-search').addEventListener('input',draw);
 $('#fm-upload').addEventListener('change',safe(async e=>{const files=[...e.target.files];e.target.value='';await upload(files);}));
 el.addEventListener('dragover',e=>{e.preventDefault();el.classList.add('fm-drag');});el.addEventListener('dragleave',()=>el.classList.remove('fm-drag'));el.addEventListener('drop',safe(async e=>{e.preventDefault();el.classList.remove('fm-drag');await upload([...e.dataTransfer.files]);}));
 const linkedSite=new URLSearchParams(location.hash.split('?')[1]||'').get('site');
 if(linkedSite&&[...$('#fm-site').options].some(o=>o.value===linkedSite))$('#fm-site').value=linkedSite;
 safe(refresh)();
}
