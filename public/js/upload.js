import {csrf,ApiError} from './api.js';
export const UPLOAD_CHUNK=8*1024*1024;
function send(site,id,offset,blob,progress){return new Promise((resolve,reject)=>{
 const xhr=new XMLHttpRequest();xhr.open('POST','/api/v1/files/upload-chunk');xhr.timeout=180000;
 xhr.setRequestHeader('Content-Type','application/octet-stream');xhr.setRequestHeader('X-CSRF-Token',csrf);
 xhr.setRequestHeader('X-Upload-Site',String(site));xhr.setRequestHeader('X-Upload-Id',id);xhr.setRequestHeader('X-Upload-Offset',String(offset));
 xhr.upload.onprogress=e=>progress(Math.min(e.loaded,blob.size));
 xhr.onerror=()=>reject(new ApiError('Conexão interrompida durante o upload.',0));
 xhr.ontimeout=()=>reject(new ApiError('Tempo de envio do bloco excedido.',0));
 xhr.onload=()=>{let data;try{data=JSON.parse(xhr.responseText);}catch{return reject(new ApiError('Resposta inválida durante o upload.',xhr.status));}
  if(xhr.status<200||xhr.status>=300)return reject(new ApiError(data.error||'Falha no upload.',xhr.status));
  if(data.offset!==offset+blob.size)return reject(new ApiError('O servidor não confirmou o bloco completo.',409));resolve(data);
 };xhr.send(blob);
});}
export async function uploadFile({file,path,site,request,onProgress}){
 let id;const started=performance.now();
 try{
  ({id}=await request('upload_begin',{path,size:file.size}));
  for(let offset=0;offset<file.size;offset+=UPLOAD_CHUNK){const chunk=file.slice(offset,offset+UPLOAD_CHUNK);
   for(let attempt=0;;attempt++)try{await send(site,id,offset,chunk,loaded=>onProgress(offset+loaded,file.size,(performance.now()-started)/1000));onProgress(offset+chunk.size,file.size,(performance.now()-started)/1000);break;}
   catch(error){if(attempt>=2||!(error.status===0||error.status>=500))throw error;await new Promise(r=>setTimeout(r,500*2**attempt));}
  }
  await request('upload_finish',{id});
 }catch(error){if(id)await request('upload_cancel',{id}).catch(()=>{});throw error;}
}
