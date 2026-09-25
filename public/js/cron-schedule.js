import {esc} from './api.js';

export const presets = [
 ['* * * * *','A cada minuto'], ['*/5 * * * *','A cada 5 minutos'],
 ['*/10 * * * *','A cada 10 minutos'], ['*/15 * * * *','A cada 15 minutos'],
 ['*/30 * * * *','A cada 30 minutos'], ['0 * * * *','A cada hora'],
 ['0 */2 * * *','A cada 2 horas'], ['0 */6 * * *','A cada 6 horas'],
 ['0 */12 * * *','A cada 12 horas'], ['daily','Todos os dias'],
 ['weekly','Toda semana'], ['monthly','Todo mês'], ['custom','Personalizado (avançado)']
];
export function scheduleFields() {
 return `<div class="full cron-schedule">
  <label>Frequência<select class="input" data-cron-preset>${presets.map(([v,label])=>`<option value="${v}" ${v==='*/5 * * * *'?'selected':''}>${label}</option>`).join('')}</select></label>
  <div class="cron-options">
   <label data-cron-clock hidden>Horário<input class="input" type="time" data-cron-time value="00:00"></label>
   <label data-cron-week hidden>Dia da semana<select class="input" data-cron-weekday>${['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'].map((d,i)=>`<option value="${i}">${d}</option>`).join('')}</select></label>
   <label data-cron-month hidden>Dia do mês<input class="input" type="number" data-cron-day value="1" min="1" max="31"></label>
  </div>
  <label data-cron-custom hidden>Expressão cron<input class="input" name="schedule" value="*/5 * * * *" maxlength="80" spellcheck="false"></label>
  <p class="helper" data-cron-preview aria-live="polite"></p>
  <p class="helper">Usa o fuso horário da VPS e a versão PHP do site. O script deve existir dentro de public_html. Acompanhe a instalação em Operações.</p>
 </div>`;
}
export function bindSchedule(form, sites) {
 const q=s=>form.querySelector(s), preset=q('[data-cron-preset]'), schedule=form.elements.schedule;
 function update() {
  const type=preset.value, timed=['daily','weekly','monthly'].includes(type), custom=type==='custom';
  for(const [selector,visible] of [['[data-cron-clock]',timed],['[data-cron-week]',type==='weekly'],['[data-cron-month]',type==='monthly'],['[data-cron-custom]',custom]]) {
   const label=q(selector);label.hidden=!visible;
   for(const input of label.querySelectorAll('input,select')) { input.required=visible; if(input!==schedule)input.disabled=!visible; }
  }
  if(timed) {
   const [hour,minute]=q('[data-cron-time]').value.split(':').map(Number);
   schedule.value=`${minute||0} ${hour||0} ${type==='monthly'?q('[data-cron-day]').value:'*'} * ${type==='weekly'?q('[data-cron-weekday]').value:'*'}`;
  } else if(!custom) schedule.value=type;
  q('[data-cron-preview]').textContent=`Agendamento: ${schedule.value}${type==='monthly'&&Number(q('[data-cron-day]').value)>28?' · Meses sem esse dia serão ignorados.':''}`;
 }
 q('.cron-schedule').addEventListener('input',update);
 q('.cron-schedule').addEventListener('change',update);
 update();
 // Selecting a site aligns its account and host; changing either filters incompatible sites.
 const server=form.elements.server_id, owner=form.elements.owner_id, site=form.elements.website_id;
 const align=()=>{const selected=sites.find(s=>String(s.id)===site.value);if(selected){server.value=String(selected.server_id);if(owner)owner.value=String(selected.owner_id);}};
 const filter=()=>{
  const previous=site.value, available=sites.filter(s=>String(s.server_id)===server.value&&String(s.owner_id)===String(owner?.value||sites[0].owner_id));
  site.innerHTML=available.length?available.map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join(''):'<option value="">Nenhum site ativo nesta conta e servidor</option>';
  if(available.some(s=>String(s.id)===previous))site.value=previous;
 };
 site.addEventListener('change',align);server.addEventListener('change',filter);owner?.addEventListener('change',filter);align();
}
