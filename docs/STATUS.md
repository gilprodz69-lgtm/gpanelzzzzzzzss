# Status de implementação — 0.2.2-preview

Versão 0.2.2: login centralizado conforme referência; gerenciador com barra de ações, navegação, favoritos da sessão, seleção por checkbox, lista/grade e menus; edição do servidor restrita a MASTER/ADMIN com permissão `servers.edit`; campos NS1/NS2 nas configurações administrativas. Salvar nameservers não instala DNS autoritativo nem altera a delegação do domínio; a ativação permanece pendente. A edição do cadastro do servidor não migra sites ou altera o IP do sistema operacional.

Correção 0.2.1: permissões dos diretórios ancestrais do painel, configuração privada independente para phpMyAdmin e preservação da senha inicial imediatamente após criar o administrador. Instalação e atualização são testadas pelo mesmo `install.sh` distribuído, incluindo diretórios preexistentes com acesso restrito.

Esta é uma versão de desenvolvimento instalável, não a conclusão integral da plataforma comercial solicitada. A especificação original continua sendo a meta. Não há módulos simulados reportando execução real.

| Área | Disponível nesta versão | Restante |
|---|---|---|
| Interface | Layout da referência, temas claro/escuro, navegação, tabelas, formulários, busca e 7 resoluções testadas | Refinamento após dados reais; editor com syntax highlighting |
| Identidade | Login, senha com hash, sessões, expiração, TOTP, revogação, link de recuperação por CLI | SMTP, recuperação pública e códigos de recuperação 2FA |
| Autorização | MASTER/ADMIN/RESELLER/CLIENT, permissões individuais, tokens com escopos | Gestão de perfis de função customizados |
| Tenants/revenda | Escopo por tenant, hierarquia, clientes, planos e permissões | Administração de organizações pelo painel; exclusão/transferência completa de contas |
| Limites | Quantidade de sites, domínios, bancos, backups, SFTP, containers, cron e clientes, incluindo pendências | Disco/tráfego/cgroups/CPU/RAM por tenant aplicados no host |
| VPS | Cadastro, segredo criptografado, concessões de acesso e múltiplos servidores | Provisionamento remoto automático e rotação de segredo pela interface |
| Métricas | CPU, RAM, disco, rede, load, uptime, histórico, alertas e polling | Agregação longa, quotas físicas e consumo por cliente |
| Sites | PHP 7.4 a 8.4 selecionável por site, handler Nginx, pool PHP isolado, usuário Unix, diretório, fila e remoção conservadora | Homologação Linux completa, edição avançada de virtual host e parâmetros PHP |
| Domínios | Alias, estacionado e redirecionamento | Edição e integração completa de certificados multi-domínio |
| SSL | HTTPS automático nos novos sites; Certbot 5.4+ para domínio/IPv4 e renovação a cada 6 horas, certificado local se ACME falhar | Revogação/remoção coordenada e alertas por validade |
| Bancos | Criação/remoção, usuário dedicado, troca de senha, conexão e phpMyAdmin com importação/exportação/SQL | Usuários adicionais e backup automatizado de banco |
| Arquivos | UI com lista/grade, busca, seleção, contexto, copiar/mover, ZIP/UNZIP, permissões, download, uploads em partes até 100 MB e lixeira com restauração; editor em tela cheia com sintaxe, indentação, blocos recolhíveis, busca/substituição, desfazer/refazer e salvamento por Ctrl+S | Edição limitada a 1 MB; operações recursivas limitadas a 5000 itens / 100 MB |
| SFTP | Criação/remover conta em chroot por site | Gestão avançada de credenciais; FTP opcional |
| Backups | Arquivos do site, SHA256, restauração, agendamento diário/semanal/mensal e retenção por API | Agenda na UI, bancos/backup completo, S3/Backblaze/Google Cloud |
| Firewall | UFW com proteção de portas essenciais e operações via fila | CIDR, IPv6 completo na UI e rollback temporizado de regras |
| Docker | Criar/remover imagem autorizada, restrições de privilégios, CPU/RAM por container | Redes, volumes, logs, inspect e ciclo completo na UI |
| Serviços/cron | Lista de serviços autorizados, start/stop/restart; cron PHP validado | Edição/pausa de cron e ajustes por serviço |
| Terminal | Reservado na lista de permissões | WebSocket, PTY isolado, autorização de sessão, limites e auditoria |
| Auditoria | Ações do painel/worker e notificações próprias | Exportação, retenção configurável e armazenamento imutável |
| SaaS/billing | Estrutura de subscriptions/invoices/payments | Fluxos comerciais, cupons, pagamentos e white-label |
| Instalação | Instalador autocontido, dependências, MariaDB, PHP, Nginx, agente, worker, métricas e HTTPS | Homologação completa em Ubuntu recém-formatado |
| Atualização | Mesmo comando detecta instalação e oferece atualização; backup, manutenção e nova pasta de release sem arquivos obsoletos | Rollback automático de migrations e atualização pelo navegador |

## Validação executada durante o desenvolvimento

- Testes de serviços, criptografia, autorização, quotas, validação e isolamento em SQLite e MariaDB real.
- Testes Python de parâmetros, caminhos, cron, HMAC, replay e idempotência.
- Testes Playwright de login, persistência de plano, CSRF, navegação, busca, tema e ausência de overflow em 1920×1080, 1440×900, 1366×768, 1024×768, 768×1024, 390×844 e 375×812.
- Capturas da interface revisadas em desktop e mobile.
- Inspeção SSH somente leitura da VPS identificou aaPanel e serviços existentes. O usuário decidiu formatar antes da instalação; não foi executada a instalação por cima do ambiente existente.

Testes unitários não substituem a homologação dos comandos privilegiados. Os serviços de Nginx, PHP-FPM, MariaDB, OpenSSH, UFW, Certbot e Docker precisam ser exercitados na VPS limpa antes de considerar esta versão pronta para clientes comerciais.
