# Loja de plugins

O menu **Plugins** oferece aplicativos aprovados para instalação no site. O primeiro aplicativo disponível é WordPress, obtido por HTTPS de `wordpress.org`, com validação de certificados e restrição da origem de redirecionamentos.

Escolha um site ativo com PHP 8.3 ou 8.4, informe o e-mail do administrador e a senha do WordPress. Não é necessário informar nome do banco, usuário SQL, senha SQL ou usuário administrativo. O painel gera esses dados e cria um banco exclusivo, respeitando as cotas e permissões da conta. Use o e-mail escolhido para entrar no WordPress.

A pasta `public_html` precisa estar vazia ou conter somente a página inicial padrão criada pelo painel. Conteúdo existente não é sobrescrito. As senhas do instalador ficam criptografadas na fila e não são incluídas em argumentos de processos, logs ou respostas públicas. Download, extração e execução do WordPress ocorrem como o usuário Linux do site, nunca como root. O agente privilegiado cria somente o banco isolado; não usa credenciais root no WordPress.

Acompanhe o resultado em **Minhas instalações**. Após concluir, use **Abrir site**, **Administrar** ou **Arquivos**. O DNS e o certificado do site devem estar configurados para acesso público HTTPS. O título inicial é o domínio do site; pode ser alterado dentro do WordPress. Temas e plugins do WordPress são administrados pelo próprio WordPress.

Em caso de falha, os dados já produzidos não são apagados automaticamente. O banco reservado aparece em Bancos de Dados e pode ser removido se não for utilizado. Se a instalação SQL tiver começado, a pasta oculta de preparação é preservada para diagnóstico. Não repita sobre conteúdo parcial sem verificar o estado. A loja não executa pacotes arbitrários enviados por URL e não remove instalações existentes.

Referência: [instalação do WordPress](https://developer.wordpress.org/reference/functions/wp_install/) e [requisitos oficiais](https://wordpress.org/about/requirements/).

A imagem `public/images/wordpress-logo.png` é o W Mark oficial, obtido em https://wordpress.org/about/logos/ e servido localmente sem alteração de proporção.
