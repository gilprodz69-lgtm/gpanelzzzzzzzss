# Acesso ao phpMyAdmin pelo painel

A opção **Bancos de Dados → Gerenciar → Abrir phpMyAdmin** cria uma conta SQL temporária, com privilégios apenas no banco selecionado. A senha usada pelos sites não é alterada. O recurso exige uma sessão interativa HTTPS e as permissões de visualizar e editar o banco; não aceita tokens de API.

O navegador recebe somente um ticket aleatório, de uso único e validade de 45 segundos, enviado por POST e vinculado à sessão do painel. A credencial SQL fica criptografada no banco do painel e chega ao phpMyAdmin por uma ponte autenticada, exclusiva de loopback. Senhas não aparecem em URLs, HTML ou respostas da API pública.

A cada requisição do phpMyAdmin, a ponte verifica novamente a sessão, a conta, suas permissões e o acesso ao banco e ao servidor. Sair do painel, suspender a conta ou revogar seu acesso bloqueia novas requisições. O acesso dura até 15 minutos, limitado também pela sessão do painel. Requisições SQL já em andamento não são revertidas ao sair.

O timer `vpsmanager-pma-cleanup.timer` remove as contas SQL vencidas a cada minuto e encerra suas conexões remanescentes. Seu serviço deve continuar ativo. O journal do agente contém apenas o hash de autenticação SQL, nunca a senha em texto. Registros criptografados vencidos são eliminados na próxima emissão de acesso.

O acesso automático atende os bancos da própria VPS do painel. Servidores remotos continuam com a orientação para usar seu painel HTTPS. O login manual do phpMyAdmin permanece disponível.

## Identidade visual

Administradores com `settings.manage` podem alterar nome e logo em **Configurações → Identidade visual**. A API também exige perfil MASTER ou ADMIN, mesmo quando outra conta recebe permissões delegadas. Logos PNG/JPEG/WebP são decodificadas e regravadas como PNG, sem metadados ou conteúdo ativo. Limites: 500 KB e 2000 × 2000 pixels.

A identidade visual fica no banco do painel e é preservada nas atualizações. O login público usa a identidade da primeira organização da instalação; após autenticar, o menu usa a organização da conta. A opção “Restaurar logo padrão” remove a imagem personalizada.
