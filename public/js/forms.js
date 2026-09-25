import{api,esc}from'./api.js';
const field=(name,label,type='text',extra='')=>`<label>${label}<input class="input" name="${name}" type="${type}" ${extra} required></label>`;
const select=(name,label,items,selected='')=>`<label>${label}<select class="input" name="${name}" required>${items.map(([v,t])=>`<option value="${esc(v)}" ${String(v)===String(selected)?'selected':''}>${esc(t)}</option>`).join('')}</select></label>`;
const limitsLabels={websites:'Sites',domains:'Domínios',databases:'Bancos',ssl_certificates:'Certificados SSL',ftp_accounts:'Contas SFTP',backups:'Backups',cron_jobs:'Cron jobs',docker_containers:'Containers',users:'Clientes',storage_mb:'Armazenamento (MB)',traffic_mb:'Tráfego (MB)',cpu:'CPU',ram_mb:'RAM (MB)'};
export async function creationForm(kind,ctx){
 let fields='',note='',title='';
 if(kind==='servers'){
  title='Adicionar Servidor';
  fields=field('name','Nome do servidor','text','placeholder="srv-br01" maxlength="190"')+field('address','IP ou hostname','text','placeholder="192.0.2.10"')+field('agent_url','Endereço HTTPS do agente','url','placeholder="https://agent.exemplo.com:9443"')+field('agent_secret','Segredo compartilhado do agente','password','minlength="32" autocomplete="new-password"')+select('os','Sistema operacional',[['Ubuntu 24.04','Ubuntu 24.04'],['Ubuntu 22.04','Ubuntu 22.04']]);
  note='Instale o agente na VPS e configure o certificado HTTPS antes de conectar. O segredo será armazenado com criptografia.';
 }else if(kind==='users'||kind==='resellers'||kind==='clients'){
  const plans=(await api('/plans')).data;
  if(!plans.length)throw new Error('Crie um plano antes de cadastrar uma conta.');
  title=kind==='resellers'?'Criar Revendedor':kind==='clients'?'Criar Cliente':'Criar Usuário';
  const roles=ctx.me.user.role==='MASTER'?[['CLIENT','Cliente'],['RESELLER','Revendedor'],['ADMIN','Administrador']]:[['CLIENT','Cliente']];
  fields=field('name','Nome completo')+field('email','E-mail','email')+field('password','Senha inicial','password','minlength="12" maxlength="72" autocomplete="new-password"')+select('role','Perfil',roles,kind==='resellers'?'RESELLER':'CLIENT')+select('plan_id','Plano',plans.map(p=>[p.id,p.name]));
  note='A senha precisa ter ao menos 12 caracteres. Conceda acesso ao servidor pelo módulo Servidores VPS.';
 }else if(kind==='plans'){
  title='Criar Plano'; fields=field('name','Nome do plano')+field('price_cents','Preço mensal (centavos)','number','min="0" value="4990"');
  fields+='<div class="full notice">Os limites de quantidade são aplicados na criação de recursos. Limites físicos de CPU, RAM, disco e tráfego dependem da integração de quotas do host, ainda em desenvolvimento.</div>';
  fields+=Object.entries(limitsLabels).map(([key,label])=>field(`limit_${key}`,label,'number',`min="0" max="100000000" value="${key==='storage_mb'?20480:key==='traffic_mb'?102400:key==='ram_mb'?1024:key==='cpu'?1:10}"`)).join('');
  fields+='<div class="full"><label>Recursos incluídos</label><div class="check-group">'+[['ftp_accounts','SFTP'],['ssl_certificates','SSL'],['backups','Backups'],['files','Arquivos'],['docker_containers','Docker']].map(([key,label])=>`<label><input type="checkbox" name="feature_${key}" ${key!=='docker_containers'?'checked':''}>${label}</label>`).join('')+'</div></div>';
 }else{
  title='Adicionar '+(ctx.me.catalog[kind]?.label||kind);
  if(!ctx.me.servers.length)throw new Error('Cadastre ou autorize um servidor antes de criar este recurso.');
  fields=select('server_id','Servidor',ctx.me.servers.map(s=>[s.id,s.name]));
  if(ctx.can('users.view')){const users=(await api('/users')).data.filter(u=>u.status==='active');fields+=select('owner_id','Proprietário',users.map(u=>[u.id,`${u.name} · ${u.role}`]),ctx.me.user.id);}
  if(['domains','ssl_certificates','ftp_accounts','backups','cron_jobs'].includes(kind)){
   const sites=(await api('/websites')).data.filter(s=>s.status==='active');
   if(!sites.length)throw new Error('Este recurso precisa de um site ativo. Crie um site e aguarde o agente concluir o provisionamento.');
   fields+=select('website_id','Site',sites.map(s=>[s.id,s.name]));
  }
  const schemas={
   websites:()=>field('domain','Domínio ou IP da VPS','text','placeholder="exemplo.com.br"')+select('php_version','Versão PHP',['7.4','8.0','8.1','8.2','8.3','8.4'].map(v=>[v,'PHP '+v]),'8.3'),
   domains:()=>field('domain','Domínio','text','placeholder="www.exemplo.com.br"')+select('type','Tipo',[['alias','Alias'],['parked','Estacionado'],['redirect','Redirecionamento']])+'<label>Destino (somente redirecionamento)<input class="input" name="target" placeholder="destino.com.br"></label>',
   databases:()=>field('name','Nome do banco','text','pattern="[a-z][a-z0-9_]{0,31}" placeholder="meu_banco"')+field('password','Senha do banco','password','minlength="12" maxlength="72" autocomplete="new-password"'),
   ssl_certificates:()=>field('email','E-mail de contato ACME','email',`value="${esc(ctx.me.user.email)}"`),
   ftp_accounts:()=>field('name','Nome do usuário SFTP','text','pattern="[a-z][a-z0-9_]{0,20}"')+field('password','Senha SFTP','password','minlength="12" maxlength="72" autocomplete="new-password"'),
   backups:()=>'<div class="full notice">Será criado um arquivo dos dados do site no servidor. Este fluxo não inclui o banco de dados.</div>',
   cron_jobs:()=>field('schedule','Expressão cron','text','value="*/5 * * * *"')+field('path','Script PHP relativo ao site','text','placeholder="tasks/rotina.php"'),
   firewall_rules:()=>field('port','Porta','number','min="1" max="65535"')+select('protocol','Protocolo',[['tcp','TCP'],['udp','UDP']])+field('source','Origem','text','value="any"')+select('action','Ação',[['allow','Permitir'],['deny','Bloquear']]),
   docker_containers:()=>field('name','Nome do container','text','pattern="[a-z][a-z0-9_]{0,31}"')+field('image','Imagem:tag','text','placeholder="nginx:stable-alpine"')+field('memory_mb','Limite de RAM (MB)','number','value="256" min="64" max="4096"')+field('cpu','Limite de CPU','number','value="1" min="1" max="4"')
  };
  fields+=schemas[kind]?.()||'';
  note=kind==='docker_containers'?'Somente imagens autorizadas pelo operador. Containers são criados sem acesso à rede, sem volumes do host e sem privilégios.':kind==='ssl_certificates'?'O DNS precisa apontar para o servidor e a porta 80 deve estar acessível para a validação Let’s Encrypt.':'A operação será executada pelo agente. Acompanhe o resultado em Operações.';
 }
 return{title,html:`${note?`<div class="notice">${esc(note)}</div>`:''}<div class="form-grid">${fields}</div>`,serialize(form){
  const values=Object.fromEntries(new FormData(form));
  for(const key of ['server_id','owner_id','website_id','plan_id','port','memory_mb','cpu','price_cents'])if(values[key]!==undefined)values[key]=Number(values[key]);
  if(kind==='plans'){values.limits={};for(const key of Object.keys(limitsLabels)){values.limits[key]=Number(values[`limit_${key}`]);delete values[`limit_${key}`];}values.features=[];for(const key of Object.keys(values))if(key.startsWith('feature_')){values.features.push(key.slice(8));delete values[key];}}
  return values;
 }};
}
export{field,select};
