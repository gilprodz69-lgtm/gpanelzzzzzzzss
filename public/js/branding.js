import{api,esc}from'./api.js';
let brand={name:'VPS Manager',logo:null};
export async function loadBranding(){try{setBranding(await api('/branding'));}catch{}}
export function setBranding(value){if(value)brand={name:value.name||'VPS Manager',logo:value.logo||null};}
export function brandMarkup(){return brand.logo?`<img class="custom-logo" src="${esc(brand.logo)}" alt="${esc(brand.name)}">`:`<img src="/images/logo.svg" alt=""><span>${esc(brand.name)}</span>`;}
export function brandingForm(){return `<section class="panel"><div class="panel-heading"><h2>Identidade visual</h2></div><div class="section-body"><form id="branding-form"><div class="form-error" role="alert"></div><label>Nome do painel<input class="input" name="name" maxlength="40" value="${esc(brand.name)}" required></label><label>Logo<input class="input" name="logo" type="file" accept="image/png,image/jpeg,image/webp"></label><p class="helper">PNG, JPEG ou WebP. Até 500 KB e 2000 × 2000 pixels. A logo aparece no login e no menu.</p><div class="branding-preview">${brandMarkup()}</div><label><input type="checkbox" name="reset"> Restaurar logo padrão</label><button type="submit" class="button primary">Salvar identidade visual</button></form></div></section>`;}
export function bindBranding(bindForm,toast){
 const form=document.querySelector('#branding-form');if(!form)return;
 let logo=brand.logo;
 const read=file=>new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(r.result);r.onerror=()=>reject(new Error('Não foi possível ler a imagem.'));r.readAsDataURL(file);});
 form.elements.logo.addEventListener('change',async()=>{try{const file=form.elements.logo.files[0];if(!file)return;if(file.size>512000||!['image/png','image/jpeg','image/webp'].includes(file.type))throw new Error('Escolha uma imagem PNG, JPEG ou WebP de até 500 KB.');logo=await read(file);form.elements.reset.checked=false;form.querySelector('.branding-preview').innerHTML=`<img class="custom-logo" src="${esc(logo)}" alt="Prévia da logo">`;}catch(e){form.elements.logo.value='';toast(e.message,true);}});
 bindForm(form,async f=>{await api('/settings',{method:'POST',body:{name:'branding',value:{name:f.elements.name.value,logo:f.elements.reset.checked?null:logo}}});const me=await api('/auth/me');setBranding(me.branding);logo=brand.logo;document.querySelector('.brand').innerHTML=brandMarkup();form.querySelector('.branding-preview').innerHTML=brandMarkup();f.elements.reset.checked=false;f.elements.logo.value='';toast('Identidade visual salva.');});
}
