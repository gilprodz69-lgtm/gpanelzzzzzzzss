# PIX: erro ao migrar um script de outra hospedagem

Uma falha de geração de PIX pode acontecer antes da chamada à operadora, ao gravar a referência local no banco. Confirme a etapa e a exceção nos logs privados da aplicação antes de mudar PHP, Nginx, certificados ou credenciais.

## Código e banco de versões diferentes

Em uma integração legada, foram identificadas estas incompatibilidades:

| Uso pelo código | Estrutura importada | Consequência |
|---|---|---|
| Metadados JSON em `payments.data` | Coluna ausente | Erro SQL 1054 antes de chamar a operadora |
| Referência textual em `payment_privatecode` | `DOUBLE` com índice único | Referências alfanuméricas convertidas em zero e possível colisão |
| Modo `Auto` | Enum aceita apenas `Manuel` e `Otomatik` | Erro ou valor truncado, conforme o modo SQL |

Desativar o modo SQL estrito não corrige uma coluna ausente nem evita perda de referências por conversão de tipos. Essas situações exigem alinhar a estrutura à versão do script.

## Procedimento de correção

1. Inspecione o INSERT e os campos usados na consulta e confirmação do pagamento, incluindo o webhook. Não registre chaves, CPF, QR Code, dados pessoais ou respostas completas da operadora.
2. Compare com `SHOW CREATE TABLE payments` no banco correspondente ao site.
3. Faça um backup privado da tabela. Teste a alteração primeiro em uma tabela temporária copiada, usando dados de teste e sem chamar a operadora.
4. Verifique que os valores de todas as linhas anteriores permanecem iguais. Para o caso acima, a adaptação foi adicionar `data` como `LONGTEXT NULL`, aceitar referências textuais em `payment_privatecode` mantendo o índice único e acrescentar `Auto` ao final do enum sem reordenar os valores existentes.
5. Aplique somente no banco afetado, com espera de bloqueio limitada. Uma mudança de tipo pode exigir reconstrução da tabela; avalie tamanho, concorrência e janela de manutenção antes de aplicá-la.
6. Confira a preservação dos registros e peça ao responsável que gere um novo PIX. A validação do banco, sozinha, não comprova geração do QR Code nem confirmação do pagamento.

Essa adaptação é específica da aplicação hospedada. O instalador e a atualização do VPS Manager **não alteram automaticamente tabelas de pagamentos, saldos ou regras dos scripts dos clientes**. Use as migrations oficiais da aplicação quando disponíveis. Não recrie cobranças nem altere status de pagamento para testar a hospedagem.
