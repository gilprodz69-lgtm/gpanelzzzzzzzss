# Compatibilidade de scripts e tarefas agendadas

O painel permite selecionar PHP 7.4 a 8.4 por site. A versão PHP não resolve, sozinha, diferenças de banco, extensões ou regras de roteamento entre hospedagens.

## Erro 500 ao gravar dados em scripts antigos

Verifique o erro exato antes de alterar configurações. Alguns scripts desativam `log_errors`; nesse caso, habilite o registro em um arquivo privado do próprio site, sem exibir erros ao visitante. Nunca publique credenciais ou os logs completos.

O erro MariaDB 1364 (`Field ... doesn't have a default value`) ocorre quando um INSERT omite uma coluna obrigatória sem valor padrão. A correção preferida é fornecer valores explícitos no script ou definir padrões adequados ao seu modelo de dados.

Para uma aplicação antiga que dependa do comportamento permissivo da hospedagem anterior, é possível adaptar **somente sua conexão PDO**, logo após criá-la:

```php
$pdo->exec("SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(REPLACE(CONCAT(',', @@SESSION.sql_mode, ','), ',STRICT_TRANS_TABLES,', ','), ',STRICT_ALL_TABLES,', ','))");
```

Use o nome real da variável PDO da aplicação. Faça backup do arquivo antes. Essa alternativa aceita os valores implícitos e conversões permissivas do MariaDB; deve ser uma decisão específica para aquela aplicação. Ela não é instalada automaticamente, não modifica tabelas ou dados existentes e não altera o modo SQL global nem as conexões de outros sites. Teste com tabelas temporárias antes de modificar um sistema em uso.

## Cron: PHP ou comando personalizado

- **PHP:** informe `tasks/rotina.php`, relativo a `public_html`. O arquivo deve existir. A execução usa a versão PHP do site; mudanças de versão também atualizam suas tarefas PHP.
- **Comando personalizado:** informe um comando de uma linha, por exemplo `curl -fsS 'https://exemplo.com/cron.php?task=all'` ou `wget -O /dev/null 'https://exemplo.com/cron.php'`. URLs com parâmetros devem ficar entre aspas. PHP CLI não recebe parâmetros HTTP: use uma chamada HTTP quando o script depender de `$_GET`.
- Escolha uma frequência predefinida ou uma expressão cron de cinco campos. Os horários seguem o fuso da VPS.

As tarefas executam com o usuário Unix do site. Comandos personalizados começam em `public_html`, dentro de um arquivo auxiliar protegido e separado do crontab, preservando `%`, aspas e redirecionamentos. O provisionamento valida a sintaxe sem executar o comando. Isso não garante sucesso da URL, das credenciais ou do programa chamado. As permissões Unix continuam se aplicando; comandos não ganham acesso root.

Tarefas existentes permanecem no formato anterior. A remoção de uma tarefa personalizada também remove seu arquivo auxiliar. O estado Ativo indica que o agendamento foi instalado, não que cada execução terminou com sucesso. Para guardar a saída, redirecione no comando para um arquivo privado acessível ao usuário do site; evite logs com segredos em `public_html`.
