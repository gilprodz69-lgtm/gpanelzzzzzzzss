import{api,esc,badge}from'./api.js';

export const domainTypes={alias:'Alias',subdomain:'Subdomínio',redirect:'Redirecionamento',parked:'Estacionado'};
const select=(name,label,options)=>`<label>${label}<select class="input" name="${name}" aria-label="${esc(label)}" required>${options.map(([value,text])=>`<option value="${esc(value)}">${esc(text)}</option>`).join('')}</select></label>`;
const input=(name,label,extra='')=>`<label>${label}<input class="input" name="${name}" ${extra} required></label>`;
export async function domainForm(ctx,preset='alias'){
 const [sitesResponse,domainsResponse]=await Promise.all([api('/websites'),api('/domains')]);
 const sites=sitesResponse.data.filter(s=>s.status==='active'),domains=domainsResponse.data.filter(d=>d.status==='active');
 if(!sites.length)throw new Error('Crie um site e aguarde a ativação antes de vincular domínios.');
 return{title:preset==='subdomain'?'Criar subdomínio':'Adicionar domínio',html:`<div class="form-grid">${select('website_id','Site de hospedagem',sites.map(s=>[s.id,s.name]))}${select('type','Tipo',Object.entries(domainTypes))}<div class="full" data-domain-name>${input('domain','Domínio completo','placeholder="exemplo.com.br" maxlength="190"')}</div><div data-subdomain>${input('prefix','Nome do subdomínio','placeholder="blog" maxlength="63" pattern="[a-zA-Z0-9](([a-zA-Z0-9]|-){0,61}[a-zA-Z0-9])?"')}</div><div data-subdomain>${select('parent_domain','Domínio principal',[])}</div><div class="full" data-redirect>${input('target','Domínio de destino','placeholder="destino.com.br" maxlength="190"')}</div><p class="full notice" data-domain-preview aria-live="polite"></p><p class="full helper">Depois de criar, abra “Detalhes / DNS” para configurar o apontamento no seu provedor DNS. HTTPS é provisionado automaticamente; o certificado público depende do DNS e da validação.</p></div>`,
 bind(form){
  const e=form.elements;e.type.value=Object.hasOwn(domainTypes,preset)?preset:'alias';
  const toggle=(selector,on)=>form.querySelectorAll(selector).forEach(el=>{el.hidden=!on;el.querySelectorAll('input,select').forEach(i=>{i.disabled=!on;i.required=on;});});
  const parents=()=>{
   const site=sites.find(s=>+s.id===+e.website_id.value);
   const names=[site.name,...domains.filter(d=>+d.config.website_id===+site.id).map(d=>d.name)].filter(n=>!/^\d+\.\d+\.\d+\.\d+$/.test(n));
   e.parent_domain.innerHTML=[...new Set(names)].map(n=>`<option value="${esc(n)}">${esc(n)}</option>`).join('');
  };
  const update=()=>{
   const sub=e.type.value==='subdomain',redirect=e.type.value==='redirect';
   toggle('[data-domain-name]',!sub);toggle('[data-subdomain]',sub);toggle('[data-redirect]',redirect);
   const host=`${e.prefix.value.toLowerCase()||'blog'}.${e.parent_domain.value}`;
   form.querySelector('[data-domain-preview]').textContent=sub?(e.parent_domain.value?`${host} terá conteúdo próprio em public_html/${host}/. Usa o PHP e a conta do site selecionado.`:'Este site usa um IP e ainda não tem um domínio. Adicione primeiro um alias, como exemplo.com.br, e aguarde a ativação.'):
    redirect?'Redireciona permanentemente (301) para HTTPS no destino, preservando caminho e parâmetros.':e.type.value==='parked'?'Reserva o domínio com resposta vazia (204).':'Exibe o mesmo conteúdo da pasta public_html do site selecionado.';
   form.querySelector('[type=submit]').disabled=sub&&!e.parent_domain.value;
  };
  e.website_id.addEventListener('change',()=>{parents();update();});form.addEventListener('input',update);form.addEventListener('change',update);parents();update();
 },serialize(form){const data=Object.fromEntries(new FormData(form));data.website_id=+data.website_id;return data;}};
}
export function domainColumns(ctx){return[
 ['Domínio',r=>`<strong>${esc(r.name)}</strong>`],['Tipo',r=>esc(domainTypes[r.config.type]||r.config.type)],
 ['Site vinculado',r=>esc(r.config.domain)],['Conteúdo / Destino',r=>esc(r.config.type==='redirect'?r.config.target:r.config.type==='parked'?'Resposta vazia':`public_html/${r.config.document_root? r.config.document_root+'/':''}`)],
 ['Status',r=>badge(r.status)],['Ações',r=>`<div class="row-actions"><button class="button small subtle" data-domain-details="${r.id}">Detalhes / DNS</button>${r.status==='active'?`<a class="button small subtle" href="https://${esc(r.name)}/" target="_blank" rel="noopener">Abrir</a>${ctx.can('files.manage')&&['alias','subdomain'].includes(r.config.type)?`<a class="button small subtle" href="#/files?site=${r.config.website_id}">Arquivos</a>`:''}`:''}${['pending','running','failed','deleting'].includes(r.status)&&ctx.can('jobs.view')?'<a class="button small subtle" href="#/jobs">Ver operação</a>':''}${['active','failed'].includes(r.status)&&ctx.can('domains.delete')?`<button class="button small" data-delete="domains:${r.id}" data-name="${esc(r.name)}">Excluir</button>`:''}</div>`]
 ];}
export function domainDetails(r){return`<dl class="detail-grid"><dt>Domínio</dt><dd>${esc(r.name)}</dd><dt>Tipo</dt><dd>${esc(domainTypes[r.config.type])}</dd><dt>Site vinculado</dt><dd>${esc(r.config.domain)}</dd><dt>Status</dt><dd>${badge(r.status)}</dd><dt>${r.config.type==='redirect'?'Destino':'Pasta'}</dt><dd>${esc(r.config.type==='redirect'?r.config.target:r.config.type==='parked'?'Sem conteúdo':`public_html/${r.config.document_root||''}`)}</dd></dl><h3>Apontamento DNS</h3><p>Crie este registro no provedor que administra o DNS do seu domínio:</p><dl class="detail-grid"><dt>Tipo</dt><dd>${esc(r.dns.type)}</dd><dt>Nome completo</dt><dd><code>${esc(r.dns.name)}</code></dd><dt>Valor</dt><dd><code>${esc(r.dns.value)}</code></dd></dl><p class="helper">Se o provedor acrescenta o domínio automaticamente, preencha somente o prefixo no campo Nome (ou @ para o domínio principal). A propagação pode levar algum tempo. Cadastrar aqui não altera o DNS externo.</p><p class="helper">O HTTPS começa com certificado local. Um certificado público depende do DNS correto e da porta 80 acessível na VPS.</p>`;}
