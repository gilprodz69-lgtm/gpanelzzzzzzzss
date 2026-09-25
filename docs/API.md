# API REST v1

Base: `/api/v1`. JSON UTF-8. Limite de corpo: 2 MiB. Tokens são criados em API, com escopos específicos e validade. Sessões de navegador exigem `X-CSRF-Token` em operações de escrita. Não habilitamos CORS público.

```bash
curl --fail --header "Authorization: Bearer $VPM_TOKEN" https://panel.exemplo.com:8443/api/v1/websites
```

| Método | Endpoint | Observação |
|---|---|---|
| POST | /auth/login | email, password, code opcional para TOTP |
| GET | /auth/me | Conta, permissões efetivas, quotas, servidores autorizados e CSRF |
| POST | /auth/logout | Sessão interativa |
| POST | /auth/reset | Token de recuperação e nova senha |
| GET | /dashboard | Contadores e atividades no escopo da conta |
| GET, POST | /users | Listagem e criação de contas |
| GET, PATCH | /users/{id} | Detalhes, nome, e-mail, plano, status e permissões |
| GET, POST | /plans | Planos e limites |
| PATCH | /plans/{id} | Atualização do plano |
| GET, POST | /servers | Cadastro e listagem das VPS |
| POST | /servers/{id}/grants | user_id, allowed |
| GET, POST | /servers/{id}/services | Consulta ou ação em serviço permitido |
| GET | /servers/{id}/metrics?hours=24 | Histórico (1–720 horas, até 3000 amostras) |
| GET, POST | /{recurso} | Listar ou solicitar criação |
| GET, DELETE | /{recurso}/{id} | Consultar ou solicitar exclusão |
| POST | /backups/{id}/restore | confirmation igual ao nome do backup |
| GET, POST | /backup_schedules | Site, frequência daily/weekly/monthly, retenção 1–30 |
| DELETE | /backup_schedules/{id} | Remove agenda e preserva cópias |
| POST | /files | website_id, action, path e parâmetros por ação |
| GET | /jobs | Operações da conta e seus resultados |
| GET | /audit_logs | Auditoria conforme permissão |
| GET | /notifications | Notificações próprias |
| POST | /notifications/{id}/read | Marcar como lida |
| GET, POST | /settings | Branding/limiares; requer settings.manage |
| GET, DELETE | /security/sessions | Sessões próprias |
| GET, POST | /security/tokens | Tokens próprios; criação exige senha atual |
| DELETE | /security/tokens/{id} | Revogar token |
| POST | /security/password | password e new_password; revoga sessões/tokens |
| POST | /security/totp/setup | Gera segredo temporário por 10 minutos |
| POST | /security/totp/enable | password e code |
| POST | /security/totp/disable | password e code atual |

Recursos: `websites`, `domains`, `databases`, `ssl_certificates`, `ftp_accounts`, `backups`, `cron_jobs`, `firewall_rules`, `docker_containers`.

Exemplo de solicitação de site:

```json
{"domain":"cliente.exemplo.com","server_id":1,"owner_id":3,"php_version":"8.3"}
```

Resposta inclui `id`, `job_id` e `status: pending`. Isso significa que a operação está na fila; consulte `/jobs` para confirmar a conclusão. Senhas de banco/SFTP são recebidas apenas na criação e criptografadas na fila.

Erros: 400 JSON inválido, 401 autenticação, 403 permissão, 404 recurso fora do escopo/inexistente, 409 conflito/limite, 419 CSRF, 422 validação, 429 bloqueio temporário, 502 falha do agente. Erros internos não retornam stack traces.

Endpoints de credenciais em `/security` exigem sessão interativa e não podem ser usados por Bearer Token. Tokens nunca podem conceder permissões que a conta não possui.
