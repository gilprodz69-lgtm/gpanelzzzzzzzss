# Deploy, distribuição e atualização

## Publicação no GitHub

Repositório: `https://github.com/gilprodz69-lgtm/gpanelzzzzzzzss`.

Execute o empacotador a partir do diretório do projeto:

```bash
python3 scripts/build-release.py
```

Artefatos:

- `install.sh`: instalador autocontido para um comando por curl.
- `dist/vps-manager-VERSAO.tar.gz`: pacote de aplicação sem segredos.
- `dist/SHA256SUMS`: checksums do pacote e instalador.
- `dist/release-manifest.json`: hashes e tamanhos dos arquivos incluídos.
- `dist/github-upload.zip`: código e instalador para envio manual, se necessário.

O instalador incorpora o pacote em base64. O hash é verificado antes da extração. Não é preciso Composer, Node ou Git na VPS para instalar.

Publique código e artefatos na branch `main`. Só divulgue o comando quando a URL raw retornar HTTP 200 e o hash do arquivo remoto coincidir com o local. Para versões estáveis, prefira uma tag/commit imutável no lugar de `main`.

## Instalação nativa

- Código: `/opt/vpsmanager/releases/TIMESTAMP`, symlink `current`.
- Ambiente: `/opt/vpsmanager/shared/.env`, root:vpsmanager 0640.
- Dados do painel: `/opt/vpsmanager/shared/storage`.
- Sites: `/srv/vpsmanager/tTENANT/sSITE/public_html`.
- Agente: `/etc/vpsmanager/agent.secret` e `/var/lib/vpsmanager-agent`.
- Banco do painel: MariaDB `vpsmanager`.
- Serviços: nginx, php8.3-fpm, mariadb, vpsmanager-agent, vpsmanager-worker e vpsmanager-metrics.timer.

O timer coleta métricas e processa agendas de backup a cada 10 segundos após a coleta anterior. O worker processa a fila continuamente. Falha de job gera notificação com estado explícito.

## Atualizar pelo mesmo comando

O `install.sh` detecta `/opt/vpsmanager/current` e oferece **1 — Atualizar Painel**. Para automação, use `VPM_UPDATE=1 bash install.sh` com o instalador já baixado.

A atualização cria backup do banco do painel, `.env` e configuração; prepara uma nova release; ativa manutenção; aguarda a fila concluir; executa migrations e troca o symlink `current`. Sites e bancos dos clientes ficam fora da pasta de código e são preservados. Arquivos antigos deixam de integrar a versão ativa. Releases anteriores e backups permanecem disponíveis para recuperação.

Logs: `/var/log/vpsmanager/update.log`. Se uma migration falhar, o painel permanece em manutenção para evitar usar código incompatível com o banco. Não repita a atualização antes de verificar o erro.

PHP 7.4, 8.0, 8.1, 8.2, 8.3 e 8.4 e extensões comuns são instalados pelo PPA Ondřej. O painel usa sempre PHP 8.3. O phpMyAdmin fica em `/phpmyadmin/`, por HTTPS, com login próprio do banco, sem login root ou servidor arbitrário.

O Certbot isolado (5.4 ou superior) solicita certificados de domínio ou IPv4 público. Certificados IP usam o perfil `shortlived`; o timer `vpsmanager-certificates.timer` verifica renovação a cada seis horas. A porta 80 precisa estar acessível. Falha ACME mantém HTTPS com certificado local e aviso explícito. A emissão pública não pode ser garantida sem validar rede e DNS.

## Atualização assinada

`scripts/update.sh` aceita pacote local, assinatura, SHA256 e chave pública previamente confiável. Ele verifica o pacote, impede traversal/links, valida sintaxe PHP, ativa manutenção, para jobs e faz backup de banco e ambiente antes de migrations e troca de release.

```bash
export RELEASE_SHA256=HASH_DO_PACOTE
export RELEASE_PUBLIC_KEY=/root/vpsmanager-release-public.pem
bash scripts/update.sh release.tar.gz release.tar.gz.sig
```

Não há serviço de publicação/assinatura ou botão de autoatualização ativo nesta versão. A chave pública deve vir de um canal confiável, não do próprio arquivo recebido.

Se a migration falhar, o painel permanece em manutenção. O script não afirma rollback completo nem restaura automaticamente o banco: a estratégia depende da migration aplicada. O backup fica em `/var/backups/vpsmanager/TIMESTAMP`; confira `previous-release`, `environment` e `database.sql` antes de recuperar.

Uma recuperação deve considerar banco, APP_KEY, código anterior, credenciais do agente, arquivos dos sites e estado dos jobs. Restaure em manutenção e só reative o worker após reconciliar operações interrompidas. Migrations futuras devem explicitar compatibilidade de rollback.

## Docker para o painel central

`compose.yaml` separa Nginx, PHP-FPM, worker e MariaDB. Configure `.env`, `DB_PASSWORD` e `DB_ROOT_PASSWORD`, execute `docker compose up -d --build` e faça a inicialização por `docker compose exec panel php scripts/console.php install` com variáveis de administrador.

O Nginx desse compose é publicado apenas em `127.0.0.1:8080`; um proxy HTTPS externo é necessário. Agende `docker compose exec -T panel php scripts/console.php metrics` a cada minuto. O compose não instala o agente root dentro do container do painel e não monta o socket Docker do host no painel.
