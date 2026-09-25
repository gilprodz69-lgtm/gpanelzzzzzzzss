# Segurança e limites desta versão

## Controles implementados

- Hash de senha nativo do PHP; token de sessão aleatório, armazenado somente como hash.
- Cookies HttpOnly, SameSite=Strict e Secure em produção; expiração absoluta de sessão.
- CSRF para escritas autenticadas por cookie, checagem de Origin, CSP sem scripts inline, escape de conteúdo e headers de segurança.
- Prepared statements e FKs compostas para vínculos do mesmo tenant.
- Bloqueio por IP e conta no login; limites nas operações sensíveis.
- TOTP com proteção contra reutilização de código; alteração de senha revoga tokens e sessões.
- API tokens com validade, hash e interseção de escopos; operações de credenciais bloqueadas para tokens.
- Criptografia autenticada de credenciais e payloads de jobs; exclusão dos segredos do payload após sucesso.
- Agente com HMAC, timestamp, nonce e idempotência persistida; sem shell arbitrário.
- Validação de domínio, nomes SQL, cron, caminho e allowlist de serviço/imagem Docker.
- Arquivos e restauração com privilégios reduzidos ao UID/GID do site.
- Auditoria dos fluxos implementados, sem senhas ou chaves no payload público.

## Limitações conhecidas

- Ainda não houve auditoria externa nem homologação completa de todos os handlers root em uma VPS limpa.
- Quotas físicas por conta, cgroups por site, proteção de banda e isolamento de código hostil ainda não foram concluídos.
- Auditoria é append-only na aplicação, mas não é imutável contra acesso direto ao banco.
- Estado entre banco do painel e sistema operacional não é transacional. Operações interrompidas exigem reconciliação; não reexecute jobs cegamente.
- Recuperação de senha gera um link por CLI administrativa. Entrega SMTP e fluxo público de solicitação ainda não foram implementados.
- Revogar sessão por interface é suportado. Ao perder o autenticador, a recuperação de TOTP requer intervenção do operador; códigos de recuperação ainda não estão disponíveis.
- FTP em texto puro e terminal root irrestrito não são oferecidos.
- HTTPS local gerado para IP tem certificado não confiável por padrão. Instale um certificado público para operação comercial.

## Segredos e distribuição

`.env`, `.tools`, dados em `storage`, screenshots de teste e credenciais locais não entram no pacote nem no repositório. O empacotador usa uma lista explícita de arquivos, valida caminhos, rejeita links e verifica ocorrências dos segredos locais conhecidos. O segredo SSH usado para inspeção da VPS ficou criptografado por DPAPI em `.tools`, fora da distribuição.

A senha root compartilhada na conversa deve ser alterada após a formatação. O instalador não contém essa senha nem precisa dela para rodar na VPS.

## Referências técnicas

- [PHP: password hashing](https://www.php.net/manual/en/function.password-hash.php)
- [PHP: gerenciamento de sessões](https://www.php.net/manual/en/session.security.management.php)
- [PHP: transações PDO](https://www.php.net/manual/en/pdo.transactions.php)
- [Python: subprocess e segurança](https://docs.python.org/3/library/subprocess.html#security-considerations)

Ao relatar um problema, envie o comportamento, a versão e uma reprodução sem senhas, APP_KEY, cookies, tokens ou arquivos privados de clientes.
