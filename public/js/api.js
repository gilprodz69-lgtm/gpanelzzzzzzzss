export let csrf='';
export function setCsrf(value){csrf=value||'';}
export class ApiError extends Error{constructor(message,status){super(message);this.status=status;}}
export async function api(path,{method='GET',body,signal}={}){
 const response=await fetch(`/api/v1${path}`,{method,credentials:'same-origin',signal,headers:{Accept:'application/json',...(body!==undefined?{'Content-Type':'application/json'}:{}),...(method!=='GET'?{'X-CSRF-Token':csrf}:{})},...(body!==undefined?{body:JSON.stringify(body)}:{})});
 let data;try{data=await response.json();}catch{throw new ApiError('O servidor retornou uma resposta inválida.',response.status);}
 if(!response.ok)throw new ApiError(data.error||'Não foi possível concluir a operação.',response.status);
 return data;
}
export const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const number=n=>new Intl.NumberFormat('pt-BR').format(n??0);
export const date=timestamp=>timestamp?new Date(timestamp*1000).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}):'—';
export const relative=t=>{const s=Math.max(0,Math.floor(Date.now()/1000-t));return s<60?'agora':s<3600?`${Math.floor(s/60)} min atrás`:s<86400?`${Math.floor(s/3600)} h atrás`:`${Math.floor(s/86400)} dias atrás`;};
const statuses={online:'Online',active:'Ativo',completed:'Concluído',pending:'Pendente',running:'Executando',deleting:'Excluindo',failed:'Falhou',offline:'Offline',unknown:'Aguardando',suspended:'Suspenso'};
export const badge=status=>`<span class="badge ${Object.hasOwn(statuses,status)?status:'unknown'}"><i class="dot ${['online','active','completed'].includes(status)?'':status==='offline'?'offline':'unknown'}"></i>${esc(statuses[status]||status)}</span>`;
