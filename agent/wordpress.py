"""Curated WordPress installer. Download, extraction and PHP run only as the site user."""
import base64
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import stat
import tempfile
import time
import urllib.request
import urllib.parse
import zipfile
from runtime import run, unprivileged
from validation import choice, password, identifier, integer, Rejected

PLACEHOLDER='<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Site ativo</title><h1>Seu site está pronto.</h1></html>'


def empty_site(public, ignore=None):
    if public.is_symlink() or not public.is_dir(): raise Rejected('Pasta do site inválida.')
    for item in public.iterdir():
        if ignore and item.name == ignore: continue
        if item.name == 'index.html' and not item.is_symlink() and item.is_file() and item.stat().st_size < 1024 and item.read_text(encoding='utf-8') == PLACEHOLDER: continue
        raise Rejected('O site já contém arquivos. Use um site vazio para instalar WordPress sem sobrescrever conteúdo.')


class OfficialRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed=urllib.parse.urlsplit(newurl)
        if parsed.scheme!='https' or parsed.hostname!='wordpress.org' or parsed.port not in (None,443):
            raise Rejected('Origem de download WordPress não autorizada.')
        return super().redirect_request(req,fp,code,msg,headers,newurl)


def download(target):
    deadline=time.monotonic()+65; size=0
    opener=urllib.request.build_opener(OfficialRedirect())
    with opener.open('https://wordpress.org/latest.zip',timeout=15) as source,target.open('xb') as out:
        while True:
            chunk=source.read(262144)
            if not chunk: break
            size+=len(chunk)
            if size>100*1048576 or time.monotonic()>deadline: raise Rejected('Download do WordPress excedeu o prazo. Tente novamente mais tarde.')
            out.write(chunk)


def extract(archive, target):
    with zipfile.ZipFile(archive) as z:
        infos=z.infolist()
        if len(infos)>15000 or sum(i.file_size for i in infos)>300*1048576: raise Rejected('Pacote WordPress inválido.')
        names=set()
        for info in infos:
            path=PurePosixPath(info.filename)
            mode=info.external_attr>>16
            if not info.filename.startswith('wordpress/') or '\\' in info.filename or '..' in path.parts or path.is_absolute() or stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in (0,stat.S_IFREG,stat.S_IFDIR)):
                raise Rejected('Caminho inválido no pacote WordPress.')
            relative=PurePosixPath(*path.parts[1:])
            if not relative.parts: continue
            if str(relative) in names: raise Rejected('Arquivo duplicado no pacote WordPress.')
            names.add(str(relative));dest=target.joinpath(*relative.parts)
            dest.parent.mkdir(parents=True,exist_ok=True)
            if info.is_dir(): dest.mkdir(exist_ok=True)
            else:
                with z.open(info) as inp,dest.open('xb') as out: shutil.copyfileobj(inp,out)
        for folder,dirs,files in os.walk(target):
            Path(folder).chmod(0o2750)
            for name in files:(Path(folder)/name).chmod(0o640)
    if not all((target/p).is_file() for p in ['wp-load.php','wp-settings.php','wp-admin/includes/upgrade.php','wp-includes/version.php']):raise Rejected('Pacote WordPress incompleto.')


def php_value(value):
    return "base64_decode('"+base64.b64encode(value.encode()).decode()+"')"


def install(ops,p):
    import pwd
    site=ops.find('site',p,'website_id')
    version=choice(site['php'],['8.3','8.4']); account=pwd.getpwnam(site['username'])
    dbname=identifier(p.get('database_name'));dbuser=identifier(p.get('database_username'))
    if not dbname.startswith(f"u{integer(p['owner_id'])}_wp_") or dbuser!=dbname:raise Rejected('Banco automático inválido.')
    admin=p.get('admin_user','');title=p.get('title','');email=p.get('admin_email','')
    if not isinstance(admin,str) or not re.fullmatch(r'[A-Za-z0-9_-]{3,40}',admin):raise Rejected('Usuário WordPress inválido.')
    if not isinstance(title,str) or not 1<=len(title.encode())<=100 or any(ord(c)<32 for c in title):raise Rejected('Título inválido.')
    if not isinstance(email,str) or len(email)>190 or not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',email):raise Rejected('E-mail inválido.')
    config={'DB_NAME':dbname,'DB_USER':dbuser,'DB_PASSWORD':password(p.get('database_password')),'DB_HOST':'localhost','DB_CHARSET':'utf8mb4','DB_COLLATE':''}
    admin_password=password(p.get('admin_password'))
    php_check=(Path(__file__).parent/'wordpress-check.php').read_text()
    php_install=(Path(__file__).parent/'wordpress-install.php').read_text()
    public=Path(site['public']); url='https://'+site['domain']
    ops.create_database({**p,'resource_id':integer(p['database_id']),'name':dbname,'username':dbuser,'password':config['DB_PASSWORD']})
    def perform():
        os.umask(0o027);empty_site(public)
        def check_database():
            try:result=json.loads(run([f'/usr/bin/php{version}','-r',php_check],input_text=json.dumps(config),timeout=15))
            except Exception:raise Rejected('Não foi possível validar o banco. Confira a senha e as permissões do usuário.')
            if not result.get('empty'):raise Rejected('O banco já contém tabelas. Selecione um banco vazio exclusivo para WordPress.')
        check_database()
        work=Path(tempfile.mkdtemp(prefix='.vpm-wordpress-',dir=public));work.chmod(0o2700)
        stage=work/'content';stage.mkdir(mode=0o2750);stage.chmod(0o2750)
        database_started=False
        try:
            download(work/'package.zip');extract(work/'package.zip',stage);(work/'package.zip').unlink()
            constants={**config,'WP_HOME':url,'WP_SITEURL':url}
            for key in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']:constants[key]=secrets.token_hex(32)
            text='<?php\n'+''.join('define('+php_value(k)+', '+php_value(v)+');\n' for k,v in constants.items())
            text+="$table_prefix = 'wp_';\ndefine('DISALLOW_FILE_EDIT', true);\ndefine('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n"
            with (stage/'wp-config.php').open('x') as out:out.write(text)
            (stage/'wp-config.php').chmod(0o640)
            check_database();empty_site(public,work.name)
            database_started=True
            result=run([f'/usr/bin/php{version}','-d','memory_limit=256M','-r',php_install],input_text=json.dumps({'path':str(stage),'title':title,'admin_user':admin,'admin_email':email,'admin_password':admin_password,'host':site['domain']}),timeout=65)
            try:result=json.loads(result)
            except Exception:raise Rejected('Instalação não concluída. Confira a operação antes de tentar novamente.')
            if not result.get('installed'):raise Rejected('Não foi possível concluir a instalação do WordPress.')
            empty_site(public,work.name)
            # Keep the panel placeholder in the private staging folder; never overwrite user files.
            if (public/'index.html').exists():os.rename(public/'index.html',work/'previous-index.html')
            for item in stage.iterdir():
                if (public/item.name).exists() or (public/item.name).is_symlink():raise Rejected('O conteúdo do site mudou durante a instalação. Publicação interrompida.')
                os.rename(item,public/item.name)
            stage.rmdir()
            # WordPress may have created cache/upload directories with its default mask.
            for folder,dirs,files in os.walk(public):
                dirs[:]=[d for d in dirs if not (Path(folder)/d).is_symlink() and d!=work.name]
                if Path(folder)!=public:Path(folder).chmod(0o2750)
                for name in files:
                    path=Path(folder)/name
                    if not path.is_symlink():path.chmod(0o640)
            shutil.rmtree(work)
            return {'message':'WordPress instalado.','version':str(result['version']),'url':url,'admin_url':url+'/wp-admin/'}
        except Exception:
            if not database_started:shutil.rmtree(work)
            # Once SQL installation starts, keep staging and DB intact for reconciliation.
            raise
    return unprivileged(account,perform)
