# Agente Linux

Serviço: `vpsmanager-agent.service`. Processo Python 3.12+ no Ubuntu 24.04, escutando exclusivamente em `127.0.0.1:9081`. Segredo em `/etc/vpsmanager/agent.secret`, modo 0600, root. Estado em `/var/lib/vpsmanager-agent`.

## Protocolo

`POST /v1/execute`, JSON com `operation`, `payload` e `request_id`. Headers:

```text
X-Timestamp: Unix timestamp em segundos
X-Nonce: 32 caracteres hexadecimais
X-Signature: HMAC-SHA256(timestamp + "\n" + nonce + "\n" + corpo HTTP exato)
```

Janela de relógio: 60 segundos; nonce de uso único. O agente mantém o digest do payload por ID. Uma operação com o mesmo ID só retorna o resultado anterior se o payload também for idêntico e a execução anterior tiver concluído.

## Operações disponíveis em código

- `metrics`, `services`, `service_action`.
- `create_site`, `delete_site`, `change_php`: Nginx com HTTPS, PHP 7.4 a 8.4, usuário Unix por site e troca de runtime com socket independente.
- `create_domain`, `delete_domain`: alias, redirecionamento e domínio estacionado.
- `create_database`, `delete_database`: banco e usuário MariaDB local.
- `create_ssl`: Certbot/Let's Encrypt com redirecionamento HTTPS.
- `create_sftp`, `delete_sftp`: OpenSSH interno com chroot; sem FTP em texto puro.
- `create_backup`, `delete_backup`, `restore_backup`: arquivos do site, armazenamento local, SHA-256.
- `create_cron`, `delete_cron`: cron numérico e script PHP preexistente dentro do site.
- `create_firewall`, `delete_firewall`: UFW; portas essenciais e SSH protegidos.
- `create_container`, `delete_container`: imagem da allowlist, UID não root, sem rede, sem mount do host, capabilities removidas e limites explícitos.
- `files`: list/read/write/mkdir/delete/rename/copy/zip/unzip/chmod.

`delete_ssl` atualmente recusa a operação com mensagem explícita: a remoção automatizada da configuração HTTPS e revogação ainda não está liberada. Não é retornado um sucesso fictício.

## Restrições importantes

O agente depende dos caminhos padrão dos pacotes Ubuntu, não dos caminhos do aaPanel. Não instale por cima de outro painel. Backups atuais contêm arquivos do site; backup/import/export de bancos e armazenamento S3 ainda não estão disponíveis. A restauração sobrescreve arquivos contidos no backup e preserva arquivos extras existentes.

Exclusão de site desativa a configuração e bloqueia a conta Unix, preservando os arquivos para recuperação do operador. A exclusão permanente desses diretórios não é oferecida pelo painel nesta versão.

Arquivos: editor de 1 MiB; upload e download em partes de 1 MiB, sem limite fixo de 100 MB por arquivo. ZIP e extração usam o espaço disponível, sem permitir links simbólicos ou caminhos externos. Operações interativas de compactação e extração continuam sujeitas ao tempo de execução do agente (170 segundos). Containers não têm portas publicadas nem redes personalizadas nesta versão.

## VPS remota

Não exponha diretamente a porta 9081. Coloque um proxy HTTPS na VPS, valide certificado no painel, restrinja a origem ao IP do painel e encaminhe somente `/v1/execute` para loopback. Cadastre a URL HTTPS e um segredo distinto por servidor. `AGENT_CA_FILE` permite uma CA privada confiável, sem desabilitar validação TLS.

O instalador vincula a VPS local por loopback com HMAC; HTTP só é autorizado para `127.0.0.1`/`localhost`. Isso evita abrir uma porta pública do agente. VPS remotas exigem o proxy e a allowlist descritos acima.
