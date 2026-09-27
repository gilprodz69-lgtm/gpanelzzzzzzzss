# Login em duas etapas

A tela inicial solicita somente e-mail e senha. Se as credenciais estiverem corretas e a conta tiver 2FA ativo, o painel apresenta uma segunda tela com o código de seis dígitos. Contas sem 2FA entram diretamente.

Validar a senha não cria uma sessão autenticada para contas com 2FA. O desafio aleatório é mantido em cookie HttpOnly/Secure/SameSite Strict, com validade de cinco minutos e até cinco tentativas. Apenas seu hash é armazenado no servidor. A senha não é conservada no formulário ou enviada novamente na segunda etapa.

O desafio é consumido atomicamente ao validar o TOTP. Alterar a senha, reconfigurar/desativar o 2FA, suspender a conta ou expirar o acesso invalida a prova pendente. Códigos TOTP já usados não são aceitos novamente. O botão **Voltar para e-mail e senha** permite iniciar outra tentativa; o próximo envio de credenciais substitui o desafio anterior.
