import{api,esc,badge,date,number}from'./api.js';
import{icon}from'./icons.js';
import{domainTypes}from'./domains.js';
const states={};
const button=(attr,label,glyph,primary=false)=>`<button class="host-action${primary?' accent':''}" ${attr} title="${esc(label)}" aria-label="${esc(label)}">${icon(glyph)}</button>`;
const link=(href,label,glyph,external=false)=>`<a class="host-action" href="${esc(href)}" title="${esc(label)}" aria-label="${esc(label)}" ${external?'target="_blank" rel="noopener"':''}>${icon(glyph)}</a>`;
const copy=(value,label)=>button(`data-copy-value="${esc(value)}"`,label,'copy');
const pathFor=r=>r.config.type==='redirect'?r.config.target:r.config.type==='parked'?'Sem conteúdo':`public_html/${r.config.document_root||''}`;
function card(label,value,total,glyph,color,progress=true){const percent=total?Math.round(value*100/total):0;return`<article class="host-stat ${color}"><span class="host-stat-icon">${icon(glyph)}</span><div><strong>${number(value)}</strong><span>${label}</span>${progress?`<div class="host-stat-progress"><progress value="${percent}" max="100" aria-label="${esc(label)}"></progress><small>${percent}%</small></div>`:''}</div></article>`;}
export async function hostingPage(kind,ctx){
 const rows=(await api('/'+kind)).data,sites=kind==='websites',state=states[kind]??={search:'',filter:'',status:'',sort:'recent',size:10,page:1,view:'list',selected:new Set()};
 state.rows=rows;state.ctx=ctx;state.selected=new Set([...state.selected].filter(id=>rows.some(r=>String(r.id)===id)));
 const quota=ctx.me.quotas[kind],limited=quota?.limit!=null&&quota.used>=quota.limit;
 const title=sites?'Sites':'Domínios';
 const create=ctx.can(kind+'.create')?`${sites?'':`<button class="button primary" data-create="domains" data-domain-type="subdomain" ${limited?'disabled':''}>${icon('plus')}Criar subdomínio</button>`}<button class="button ${sites?'primary':''}" data-create="${kind}" ${limited?'disabled':''}>${icon(sites?'plus':'domains')}${sites?'Adicionar site':'Adicionar domínio'}</button>`:'';
 const active=rows.filter(r=>r.status==='active').length,paused=rows.filter(r=>r.status==='suspended').length,pending=rows.filter(r=>['pending','running','deleting'].includes(r.status)).length;
 const options=sites?ctx.me.servers.map(s=>[s.id,s.name]):Object.entries(domainTypes);
 return`<section class="hosting-page" data-hosting="${kind}"><header class="host-heading"><div class="host-title"><span class="host-stat-icon">${icon('domains')}</span><div><h1>${title}</h1><p>${sites?'Gerencie os sites hospedados na sua infraestrutura.':'Gerencie domínios, subdomínios, aliases e redirecionamentos.'}</p></div></div><div class="host-heading-actions">${create}<button class="button" data-action="refresh" aria-label="Atualizar lista">${icon('refresh')}</button></div></header><div class="host-stats">${card(sites?'Total de sites':'Total de domínios',rows.length,rows.length,'domains','blue',false)}${card('Ativos',active,rows.length,'check','green')}${card('Pausados',paused,rows.length,'pause','orange')}${card('Inativos',rows.filter(r=>['offline','failed','unknown','inactive'].includes(r.status)).length,rows.length,'close','red')}</div>${pending?`<p class="helper">${pending} operação(ões) em andamento. Acompanhe a situação de cada registro na lista.</p>`:''}${limited?'<p class="notice">Limite do plano atingido.</p>':''}<section class="host-panel"><div class="host-toolbar"><div class="host-search">${icon('search')}<input type="search" data-host-search value="${esc(state.search)}" placeholder="${sites?'Pesquisar por nome, domínio ou IP…':'Pesquisar domínio, subdomínio ou IP…'}" aria-label="Pesquisar nesta lista"><button class="icon-button" data-host-clear aria-label="Limpar pesquisa">${icon('close')}</button></div>${!sites||ctx.me.user.role!=='CLIENT'?`<select class="input" data-host-filter aria-label="${sites?'Filtrar servidor':'Filtrar tipo'}"><option value="">${sites?'Todos os servidores':'Todos os tipos'}</option>${options.map(([id,name])=>`<option value="${esc(id)}" ${String(id)===state.filter?'selected':''}>${esc(name)}</option>`).join('')}</select>`:''}<select class="input" data-host-status aria-label="Filtrar status">${[['','Todos os status'],['active','Ativos'],['suspended','Pausados'],['pending','Pendentes'],['running','Executando'],['failed','Falhas'],['deleting','Excluindo']].map(([id,name])=>`<option value="${id}" ${id===state.status?'selected':''}>${name}</option>`).join('')}</select><select class="input" data-host-sort aria-label="Ordenação">${[['recent','Mais recentes'],['old','Mais antigos'],['name','Nome A–Z'],['name-desc','Nome Z–A']].map(([id,name])=>`<option value="${id}" ${id===state.sort?'selected':''}>${name}</option>`).join('')}</select><div class="host-views">${button('data-host-view="list"','Visualização em lista','list')}${button('data-host-view="grid"','Visualização em grade','grid')}</div></div><div data-host-selection class="host-selection" hidden></div><div data-host-results></div><footer class="host-footer"><label><select class="input" data-host-size aria-label="Itens por página">${[10,25,50,100].map(n=>`<option ${n===state.size?'selected':''}>${n}</option>`).join('')}</select>itens por página</label><span data-host-count></span><div class="host-pagination">${button('data-host-prev','Página anterior','back')}<span data-host-page></span>${button('data-host-next','Próxima página','arrow')}</div></footer></section></section>`;
}
function actions(kind,r,ctx){
 const site=kind==='websites',active=r.status==='active',editing=active||r.status==='failed'&&r.config.pending_update;
 let main=site?(editing&&ctx.can('websites.edit')?button(`data-edit-site="${r.id}"`,'Editar site','edit',true):''):button(`data-domain-details="${r.id}"`,'Detalhes / DNS','domains');
 if(active){
  main+=link(`https://${r.name}/`,site?'Abrir site':'Abrir domínio','external',true);
  if(ctx.can('files.manage')&&(site||['alias','subdomain'].includes(r.config.type)))main+=link(`#/files?site=${site?r.id:r.config.website_id}`,site?'Gerenciar arquivos':'Arquivos','files');
 }
 main+=site?button(`data-site-details="${r.id}"`,'Detalhes','settings'):(editing&&ctx.can('domains.edit')?button(`data-edit-domain="${r.id}"`,'Editar domínio','edit'):'');
 if(site&&ctx.can('metrics.view')&&ctx.me.user.role!=='CLIENT')main+=button(`data-host-metrics="${r.server_id}"`,'Métricas do servidor','chart');
 let more='';
 if(site&&active&&ctx.can('websites.edit'))more+=`<button data-php="${r.id}" data-version="${esc(r.config.php_version)}">${icon('settings')}Alterar PHP</button>`;
 if(ctx.can('jobs.view'))more+='<a href="#/jobs">'+icon('jobs')+'Ver operações</a>';
 if(['active','failed'].includes(r.status)&&ctx.can(kind+'.delete'))more+=`<button class="danger" data-delete="${kind}:${r.id}" data-name="${esc(r.name)}">${icon('trash')}Excluir</button>`;
 if(more)main+=`<details class="host-more"><summary title="Mais ações" aria-label="Mais ações">${icon('more')}</summary><div>${more}</div></details>`;
 return`<div class="host-actions">${main}</div>`;
}
export function bindHosting(){
 const el=document.querySelector('[data-hosting]');if(!el)return;
 const kind=el.dataset.hosting,s=states[kind],site=kind==='websites',ctx=s.ctx,$=q=>el.querySelector(q);
 function draw(){
  const term=s.search.trim().toLocaleLowerCase('pt-BR');
  let rows=s.rows.filter(r=>{const server=ctx.me.servers.find(v=>+v.id===+r.server_id);return (!term||[r.name,r.config.domain,r.config.target,server?.address].filter(Boolean).join(' ').toLocaleLowerCase('pt-BR').includes(term))&&(!s.filter||String(site?r.server_id:r.config.type)===s.filter)&&(!s.status||r.status===s.status);});
  rows.sort((a,b)=>s.sort.startsWith('name')?a.name.localeCompare(b.name)*(s.sort==='name'?1:-1):((a.created_at||a.id)-(b.created_at||b.id))*(s.sort==='old'?1:-1));
  const pages=Math.max(1,Math.ceil(rows.length/s.size));s.page=Math.max(1,Math.min(s.page,pages));const visible=rows.slice((s.page-1)*s.size,s.page*s.size);
  const serverColumn=site&&ctx.me.user.role!=='CLIENT';
  const headings=['',site?'Site':'Domínio',...(site?['Domínio / IP',...(serverColumn?['Servidor']:[]),'Proprietário']:['Tipo','Site relacionado','IP / Host','Caminho / Destino']),'Status',...(site?['PHP','Criado em']:['Expira em']),'Ações'];
  const render=r=>{
   const server=ctx.me.servers.find(v=>+v.id===+r.server_id),address=server?.address||'—';
   const cells=[`<input type="checkbox" data-host-check="${r.id}" aria-label="Selecionar ${esc(r.name)}" ${s.selected.has(String(r.id))?'checked':''}>`,`<div class="host-name"><span class="host-domain-icon">${icon('domains')}</span><div><strong>${esc(r.name)}</strong><small>${site?'/public_html':esc(domainTypes[r.config.type]||r.config.type)}</small></div>${copy(r.name,'Copiar domínio')}</div>`];
   if(site){cells.push(`<span class="host-copy">${esc(address)}${address!=='—'?copy(address,'Copiar IP') :''}</span>`);if(serverColumn)cells.push(`<div class="host-name"><span class="host-server-icon">${icon('servers')}</span><div><strong>${esc(server?.name||'#'+r.server_id)}</strong><small>${esc(server?.os||'')}</small></div></div>`);cells.push(`${icon('users')} #${r.owner_id}`);}
   else cells.push(esc(domainTypes[r.config.type]),esc(r.config.domain),esc(address),`<span class="host-path" title="${esc(pathFor(r))}">${esc(pathFor(r))}</span>${copy(pathFor(r),'Copiar caminho ou destino')}`);
   cells.push(badge(r.status));
   if(site)cells.push(`<span class="host-php">${esc(r.config.php_version)} <em>php</em></span>`,`${icon('jobs')} ${date(r.created_at)}`);else cells.push('<span title="A validade do registro é administrada no registrador do domínio">—</span>');
   cells.push(actions(kind,r,ctx));
   return`<tr data-search-row>${cells.map((c,i)=>`<td data-label="${headings[i]}" ${i===cells.length-1?'class="actions-cell"':''}>${c}</td>`).join('')}</tr>`;
  };
  $('[data-host-results]').className=s.view==='grid'?'host-results host-grid':'host-results';
  $('[data-host-results]').innerHTML=visible.length?`<table class="data-table"><thead><tr>${headings.map((h,i)=>`<th>${i===0?`<input type="checkbox" data-host-all aria-label="Selecionar página" ${visible.every(r=>s.selected.has(String(r.id)))?'checked':''}>`:h}</th>`).join('')}</tr></thead><tbody>${visible.map(render).join('')}</tbody></table>`:`<div class="empty">${icon('domains')}<strong>${s.rows.length?'Nenhum resultado encontrado':'Nenhum registro por enquanto'}</strong><p>${s.rows.length?'Ajuste a pesquisa ou os filtros.':`Use o botão acima para adicionar ${site?'seu primeiro site':'um domínio ou subdomínio'}.`}</p></div>`;
  $('[data-host-count]').textContent=`Mostrando ${rows.length?(s.page-1)*s.size+1:0}–${Math.min(s.page*s.size,rows.length)} de ${rows.length} registro(s)`;
  $('[data-host-page]').textContent=`${s.page} / ${pages}`;$('[data-host-prev]').disabled=s.page<=1;$('[data-host-next]').disabled=s.page>=pages;
  el.querySelectorAll('[data-host-view]').forEach(b=>{b.classList.toggle('active',b.dataset.hostView===s.view);b.setAttribute('aria-pressed',String(b.dataset.hostView===s.view));});
  const selection=$('[data-host-selection]');selection.hidden=!s.selected.size;selection.innerHTML=`<span>${s.selected.size} selecionado(s)</span><button class="button small" data-copy-value="${esc(s.rows.filter(r=>s.selected.has(String(r.id))).map(r=>r.name).join('\n'))}">${icon('copy')}Copiar endereços</button><button class="button small" data-host-deselect>Limpar seleção</button>`;
  const all=$('[data-host-all]');if(all)all.addEventListener('change',()=>{visible.forEach(r=>all.checked?s.selected.add(String(r.id)):s.selected.delete(String(r.id)));draw();});
 }
 el.addEventListener('input',e=>{if(e.target.matches('[data-host-search]')){s.search=e.target.value;s.page=1;draw();}});
 el.addEventListener('change',e=>{for(const [attr,key]of[['filter','filter'],['status','status'],['sort','sort'],['size','size']])if(e.target.matches(`[data-host-${attr}]`)){s[key]=key==='size'?+e.target.value:e.target.value;s.page=1;draw();return;}if(e.target.dataset.hostCheck){e.target.checked?s.selected.add(e.target.dataset.hostCheck):s.selected.delete(e.target.dataset.hostCheck);draw();}});
 el.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;if(b.hasAttribute('data-host-clear')){s.search='';$('[data-host-search]').value='';s.page=1;}else if(b.dataset.hostView)s.view=b.dataset.hostView;else if(b.hasAttribute('data-host-prev'))s.page--;else if(b.hasAttribute('data-host-next'))s.page++;else if(b.hasAttribute('data-host-deselect'))s.selected.clear();else return;draw();});
 draw();
}
