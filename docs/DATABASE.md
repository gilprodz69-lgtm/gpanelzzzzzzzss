# Banco de dados

Migrations em `database/migrations`, aplicadas por `php scripts/console.php migrate`. Produção usa MariaDB/InnoDB/utf8mb4. PDO usa prepared statements e desativa emulação no MySQL. SQLite é usado somente no desenvolvimento e nos testes rápidos.

Tabelas implementadas:

| Grupo | Tabelas |
|---|---|
| Identidade | tenants, users, roles, permissions, user_roles, user_permissions |
| Autenticação | sessions, api_keys, rate_limits, password_resets |
| Revenda | plans; hierarquia em users.parent_id e users.role |
| Infraestrutura | servers, server_credentials, server_users, server_metrics |
| Hospedagem | websites, domains, databases, ssl_certificates, ftp_accounts |
| Operações | jobs, backups, backup_schedules, cron_jobs, firewall_rules, docker_containers |
| Administração | audit_logs, notifications, settings, migrations |
| Faturamento preparado | subscriptions, invoices, payments |

Cada tabela de recurso possui proprietário, tenant, servidor, nome, estado, configuração JSON validada e timestamps. Há índices de escopo e unicidade de nome por servidor. Recursos excluídos preservam histórico com `deleted_at` e têm o nome de índice liberado após sucesso do agente.

Segredos não entram no JSON público. Senhas de usuários usam `password_hash`; chaves de API e sessões armazenam hash SHA-256 de tokens aleatórios. Credenciais do agente, TOTP e payloads sensíveis usam secretbox com APP_KEY externa ao banco. Ao concluir um job, o payload secreto é descartado.

Ainda não foram criadas tabelas sem fluxo funcional só para completar a lista da especificação: usuários adicionais de banco, imagens/volumes Docker, consumo faturável, cupons, transações e outros módulos serão incluídos nas migrations das próximas fases. Revenda não possui tabela duplicada: a relação atual é explícita em `users`.

Faça backup do banco **e** de `APP_KEY`. Um backup sem a chave não permite decifrar os segredos. O banco é parte da fronteira de confiança; acesso direto de administradores ao banco pode alterar autorização, fila e auditoria.
