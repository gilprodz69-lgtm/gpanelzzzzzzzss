# Status de implementação — 0.1.0-preview

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
| Sites | Handler Nginx, pool PHP isolado, usuário Unix, diretório, fila e remoção conservadora | Homologação Linux completa, edição avançada de virtual host e parâmetros PHP |
| Domínios | Alias, estacionado e redirecionamento | Edição e integração completa de certificados multi-domínio |
| SSL | Solicitação Certbot e renovação via timer Certbot | Revogação/remoção coordenada e alertas por validade |
| Bancos | Criação e remoção com usuário dedicado e segredo cifrado | Importação/exportação, usuários adicionais, senha e backup de banco |
| Arquivos | API para listar/ler/gravar/mover/copiar/ZIP/UNZIP/permissões; UI para listar/editar/upload/criar/excluir | Download na UI, ações avançadas na UI, editor com realce de sintaxe |
| SFTP | Criação/remover conta em chroot por site | Gestão avançada de credenciais; FTP opcional |
| Backups | Arquivos do site, SHA256, restauração, agendamento diário/semanal/mensal e retenção por API | Agenda na UI, bancos/backup completo, S3/Backblaze/Google Cloud |
| Firewall | UFW com proteção de portas essenciais e operações via fila | CIDR, IPv6 completo na UI e rollback temporizado de regras |
| Docker | Criar/remover imagem autorizada, restrições de privilégios, CPU/RAM por container | Redes, volumes, logs, inspect e ciclo completo na UI |
| Serviços/cron | Lista de serviços autorizados, start/stop/restart; cron PHP validado | Edição/pausa de cron e ajustes por serviço |
| Terminal | Reservado na lista de permissões | WebSocket, PTY isolado, autorização de sessão, limites e auditoria |
| Auditoria | Ações do painel/worker e notificações próprias | Exportação, retenção configurável e armazenamento imutável |
| SaaS/billing | Estrutura de subscriptions/invoices/payments | Fluxos comerciais, cupons, pagamentos e white-label |
| Instalação | Instalador autocontido, dependências, MariaDB, PHP, Nginx, agente, worker, métricas e HTTPS | Homologação completa em Ubuntu recém-formatado |
| Atualização | Script com assinatura, backup, manutenção e troca de release | Canal oficial assinado e autoatualização pelo painel |

## Validação executada durante o desenvolvimento

- Testes de serviços, criptografia, autorização, quotas, validação e isolamento em SQLite e MariaDB real.
- Testes Python de parâmetros, caminhos, cron, HMAC, replay e idempotência.
- Testes Playwright de login, persistência de plano, CSRF, navegação, busca, tema e ausência de overflow em 1920×1080, 1440×900, 1366×768, 1024×768, 768×1024, 390×844 e 375×812.
- Capturas da interface revisadas em desktop e mobile.
- Inspeção SSH somente leitura da VPS identificou aaPanel e serviços existentes. O usuário decidiu formatar antes da instalação; não foi executada a instalação por cima do ambiente existente.

Testes unitários não substituem a homologação dos comandos privilegiados. Os serviços de Nginx, PHP-FPM, MariaDB, OpenSSH, UFW, Certbot e Docker precisam ser exercitados na VPS limpa antes de considerar esta versão pronta para clientes comerciais.
