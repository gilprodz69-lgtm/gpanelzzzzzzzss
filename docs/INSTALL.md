# Instalação

## Requisitos

- VPS limpa com Ubuntu Server 24.04, arquitetura amd64 ou arm64, 2 GB de RAM e 5 GB livres.
- Acesso root, saída HTTPS/HTTP para os repositórios oficiais e DNS funcional.
- Portas 80/443 para hospedagem e 8443 para o painel; SSH na porta já configurada.
- Para certificado público: domínio apontado ao IP, incluindo registros AAAA corretos quando houver IPv6.

O instalador não formata discos, não exclui sites existentes e recusa instalações conhecidas de outros painéis. Ele instala pacotes e cria serviços de sistema; utilize um servidor destinado a este projeto.

## Um comando

Depois de `install.sh` estar publicado na branch `main`:

```bash
curl -fsSL https://raw.githubusercontent.com/gilprodz69-lgtm/gpanelzzzzzzzss/main/install.sh | bash
```

O arquivo é autocontido: inclui o código compactado e verifica o SHA-256 do conteúdo antes de extrair. A transferência HTTPS e a integridade da conta GitHub protegem a origem; o checksum embutido não substitui uma assinatura independente.

Perguntas: domínio/IP e e-mail. A senha inicial é gerada automaticamente. Não é necessário informar a senha root ao instalador.

Se preferir inspecionar antes de executar:

```bash
curl -fsSLo /root/vpsmanager-install.sh https://raw.githubusercontent.com/gilprodz69-lgtm/gpanelzzzzzzzss/main/install.sh
less /root/vpsmanager-install.sh
bash /root/vpsmanager-install.sh
```

Também é possível enviar `install.sh` por SFTP e executar `bash install.sh`. Nesse caso o primeiro download do projeto é desnecessário, mas a instalação de pacotes ainda precisa de internet.

## Parâmetros não interativos

```bash
export PANEL_HOST=panel.exemplo.com
export ADMIN_EMAIL=admin@exemplo.com
export PANEL_PORT=8443
export INSTALL_DOCKER=1
bash install.sh
```

`ADMIN_PASSWORD` é opcional, com 12 a 72 caracteres. Se omitido, gera uma senha aleatória. Evite colocar senhas no histórico do shell; prefira a geração automática.

## Primeiros passos

1. Abra a URL HTTPS informada pelo instalador.
2. Entre com as credenciais de `/root/vpsmanager-access.txt`.
3. Ative 2FA em Configurações e troque a senha inicial.
4. Confira se a VPS cadastrada automaticamente recebe métricas.
5. Crie planos e contas; em Servidores VPS, autorize o servidor para cada revenda/cliente.
6. Crie um site. Aguarde a operação ficar concluída antes de emitir SSL ou criar contas SFTP.
7. Aponte o domínio ao IP da VPS e emita o certificado SSL.

Os planos limitam quantidade de recursos. As quotas físicas de disco/tráfego/CPU/RAM ainda exigem desenvolvimento e homologação; não anuncie esses limites como garantia comercial nesta versão.

## Diagnóstico

```bash
systemctl status vpsmanager-agent vpsmanager-worker vpsmanager-metrics.timer
journalctl -u vpsmanager-agent -n 100 --no-pager
journalctl -u vpsmanager-worker -n 100 --no-pager
tail -n 100 /var/log/vpsmanager/install.log
sudo -u vpsmanager php8.3 /opt/vpsmanager/current/scripts/health.php
```

Se o instalador falhar, confira a etapa indicada. Não reexecute cegamente uma instalação parcial nem remova bancos/arquivos para forçar a passagem. O instalador preserva o erro e os arquivos para diagnóstico.

Certificado local não é confiável automaticamente por navegadores. Para uso público, aponte um domínio, emita um certificado público e ajuste `APP_URL` e o virtual host de forma consistente. Cookies continuam marcados como Secure.

## Desenvolvimento sem runtimes portáteis

Instale PHP >=8.3 com PDO SQLite/MySQL, sodium, curl e mbstring. Copie `.env.example` para `.env`, gere `APP_KEY` com `php scripts/console.php key:generate` e configure o DSN. Para SQLite local, use `DB_DSN=sqlite:/caminho/absoluto/storage/panel.sqlite`, `APP_ENV=local`, `COOKIE_SECURE=0` e `APP_URL=http://127.0.0.1:8080`.

Defina `ADMIN_EMAIL` e `ADMIN_PASSWORD` no ambiente e execute `php scripts/console.php install`. Inicie com `php -S 127.0.0.1:8080 -t public public/router.php`. Esse servidor é apenas para desenvolvimento.
