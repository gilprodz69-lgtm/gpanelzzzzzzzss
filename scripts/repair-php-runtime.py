"""Migrate PHP temporary storage out of the file manager's public root."""
import sys,json
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'agent'))
from operations import Operations,repair_site_runtime
from runtime import run
ops=Operations();versions=set()
for row in ops.catalog.execute("SELECT data FROM resources WHERE kind='site'").fetchall():
    site=json.loads(row['data'])
    if repair_site_runtime(site):versions.add(site['php'])
for version in sorted(versions):run([f'/usr/sbin/php-fpm{version}','-t'])
for version in sorted(versions):run(['/usr/bin/systemctl','reload',f'php{version}-fpm'])
print(f'PHP: diretórios privados verificados; {len(versions)} versão(ões) atualizada(s).')
