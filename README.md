# VPS Manager

Painel de VPS, hospedagem e revenda com interface em português baseada na referência visual fornecida, PHP 8.3+, MariaDB, API REST e agente Linux separado.

**Versão em desenvolvimento.** O núcleo e a interface possuem testes automatizados. Os módulos avançados e a homologação completa em Ubuntu estão discriminados em [STATUS](docs/STATUS.md). Esta versão não representa a conclusão das 52 seções da especificação.

## Instalação com um comando

Após publicar os arquivos na branch `main` deste repositório, execute como root em uma **VPS Ubuntu 24.04 recém-formatada**:

```bash
curl -fsSL https://raw.githubusercontent.com/gilprodz69-lgtm/gpanelzzzzzzzss/main/install.sh | bash
```

O instalador pede o domínio ou IP e o e-mail do administrador. Gera uma senha aleatória e configura MariaDB, PHP-FPM, Nginx, agente, worker, monitoramento e HTTPS na porta **8443**. A VPS local é cadastrada automaticamente. A senha inicial é mostrada no terminal e guardada em `/root/vpsmanager-access.txt`, com acesso exclusivo de root.

Sem domínio, usa certificado HTTPS local: o navegador apresentará aviso de confiança. Com domínio e DNS correto, tenta emitir certificado público Let's Encrypt. Não abre a porta do MariaDB nem a porta do agente. Se houver firewall externo no provedor, libere TCP 8443, 80 e 443 por lá. O SSH existente é preservado.

O instalador recusa aaPanel/CyberPanel/cPanel e serviços de hospedagem já ativos. Ubuntu 22.04 também é previsto, usando o repositório `ppa:ondrej/php` para PHP 8.3; Ubuntu 24.04 utiliza os pacotes nativos.

Consulte [INSTALL](docs/INSTALL.md) para parâmetros, instalação sem internet no primeiro download, diagnóstico e restrições.

## Desenvolvimento local no Windows

Os runtimes portáteis preparados nesta máquina ficam em `.tools/`, que não é publicado. Para iniciar:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/dev.ps1
```

Acesse `http://127.0.0.1:8080`. As credenciais locais foram geradas em `storage/LOCAL-ACCESS.txt`, fora do controle de versão. Não há senha padrão no código.

O desenvolvimento local usa SQLite, e as mesmas migrations e serviços são testados separadamente no MariaDB. A instalação Ubuntu usa MariaDB.

## Estrutura

```text
app/           Controllers, serviços, validações, autorização e repositórios
agent/         Agente Linux com HMAC e operações explícitas
database/      Migrations versionadas
public/        Entrada PHP, CSS e módulos JavaScript
resources/     View da aplicação
scripts/       Instalador, console, atualização e empacotamento
deploy/        Docker, Nginx e serviços systemd
tests/         Testes PHP, Python e navegador
docs/          Arquitetura, API, instalação e status dos requisitos
dist/          Pacote de distribuição e checksums gerados
```

## Testes

```bash
php tests/run.php
python3 tests/test_agent.py
```

Com banco de testes dedicado e credenciais fornecidas por variáveis de ambiente:

```bash
DB_USER=root DB_PASSWORD='SENHA_DO_BANCO_DE_TESTES' TEST_MYSQL_PORT=3307 php tests/mysql.php
```

Esse último comando cria e remove apenas um banco temporário com nome aleatório `vpm_test_*`.

Testes de navegador: `npm ci` e `npx playwright install chromium`, seguidos de `npm run test:browser`, com o servidor local e credenciais locais preparados. O teste cria um plano para conferir persistência. Em produção, nunca execute testes de navegador com credenciais de clientes.

## Documentação

- [Instalação](docs/INSTALL.md)
- [Arquitetura](docs/ARCHITECTURE.md)
- [Banco de dados](docs/DATABASE.md)
- [API](docs/API.md)
- [Agente](docs/AGENT.md)
- [Segurança](docs/SECURITY.md)
- [Deploy e atualização](docs/DEPLOY.md)
- [Status e próximos módulos](docs/STATUS.md)
- [Referência aaPanel](docs/AAPANEL-REFERENCE.md)

O código desta implementação é próprio. Nenhum código, marca ou pacote do aaPanel foi incorporado.
