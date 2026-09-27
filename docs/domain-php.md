# PHP por domínio e pastas de subdomínios

Em Domínios, abra Mais ações e escolha Alterar PHP. A opção aparece para aliases e subdomínios ativos vinculados a um site, com permissões de edição de domínios e sites. Redirecionamentos e domínios estacionados não executam PHP.

Até escolher uma versão própria, o domínio acompanha o PHP do site. Depois da escolha, recebe um pool PHP-FPM exclusivo, mantendo seus arquivos, sua conta e as configurações de execução do site. Alterar o PHP do site principal não modifica esses pools independentes. A troca valida o PHP e o Nginx, aguarda o novo socket e as conexões antigas e restaura as configurações anteriores se falhar. Nenhuma versão dos domínios existentes é alterada durante a atualização do painel.

Ao criar um subdomínio, Nome da pasta define um diretório novo dentro de public_html. O nome padrão continua sendo o endereço completo do subdomínio. Não são aceitos caminhos absolutos, barras, pastas ocultas ou pastas existentes; assim a criação não sobrescreve conteúdo. Renomear o domínio posteriormente mantém sua pasta. Excluir o domínio remove seu endereço e eventual pool PHP exclusivo, preservando arquivos.

As crons vinculadas ao site continuam usando a versão PHP do site. A seleção de PHP do domínio se aplica às requisições web recebidas por esse endereço.
