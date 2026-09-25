import {esc,badge,number} from './api.js';
export const fullDate=value=>value?new Date(Number(value)*1000).toLocaleString('pt-BR'):'Não definida';
export const validity=user=>user.role==='RESELLER'?`${esc(fullDate(user.expires_at))}${user.expired?' · Vencida':''}`:'—';
export function accountActions(r,can,admin,currentId) {
 let html=`<button class="button small subtle" data-user-details="${r.id}">Detalhes</button>`;
 if(r.role!=='MASTER'&&Number(r.id)!==Number(currentId)&&can('users.edit')) {
  html+=`<button class="button small subtle" data-edit-user="${r.id}">Editar</button>`;
  if(r.role==='RESELLER'&&admin)html+=`<button class="button small subtle" data-renew-user="${r.id}">Renovar validade</button>`;
  html+=`<button class="button small subtle" data-user-password="${r.id}">Alterar senha</button><button class="button small" data-user-status="${r.id}" data-status="${r.status==='active'?'suspended':'active'}">${r.status==='active'?'Suspender':'Ativar'}</button>`;
 }
 return `<div class="account-actions">${html}</div>`;
}
const details=rows=>`<dl class="details-grid">${rows.map(([name,value])=>`<div><dt>${esc(name)}</dt><dd>${value}</dd></div>`).join('')}</dl>`;
export function accountDetails(r) {
 const u=r.user;
 return details([['Nome',esc(u.name)],['E-mail',esc(u.email)],['Perfil',esc(u.role)],['Plano',esc(r.plan_name||'Não definido')],['Status',badge(u.expired?'expired':u.status)],['Criado em',esc(fullDate(u.created_at))],['Validade',validity(u)],['Clientes',number(r.clients)]])+ '<p class="helper">A validade encerra o acesso ao painel da revenda e dos clientes subordinados. Os sites permanecem no servidor.</p>';
}
export function databaseDetails(r) {
 const copy=value=>`<strong>${esc(value)}</strong> <button type="button" class="button small subtle" data-copy-value="${esc(value)}" aria-label="Copiar ${esc(value)}">Copiar</button>`;
 const rows=[['Banco',copy(r.name)],['Usuário',copy(r.username)],['Host',copy(r.host)],['Porta',esc(r.port)],['Status',badge(r.status)],['Criado em',esc(fullDate(r.created_at))],['Charset',esc(r.charset)],['Collation',esc(r.collation||'Indisponível')],['Tabelas',r.tables==null?'Indisponível':number(r.tables)],['Tamanho',r.size_bytes==null?'Indisponível':`${(r.size_bytes/1048576).toLocaleString('pt-BR',{maximumFractionDigits:2})} MB`]];
 if(r.server)rows.push(['Servidor',esc(r.server)]);
 return details(rows)+(r.stats_message?`<p class="notice">${esc(r.stats_message)}</p>`:'')+`<p class="helper">Use o host acima em aplicações hospedadas nesta VPS. A senha não é exibida; utilize a senha definida na criação ou altere-a abaixo.</p><p>${r.phpmyadmin_url?`<a class="button primary" href="${esc(r.phpmyadmin_url)}" target="_blank" rel="noopener">Abrir phpMyAdmin</a>`:'O phpMyAdmin deve ser acessado pelo painel HTTPS da hospedagem.'}</p>`;
}
