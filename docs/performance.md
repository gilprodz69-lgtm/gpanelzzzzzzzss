# Compressão de arquivos da interface

O instalador Ubuntu inclui `deploy/nginx-performance.conf` no contexto HTTP do Nginx. A configuração padrão do Ubuntu já ativa gzip para HTML; o complemento habilita CSS, JavaScript e SVG, com nível 5, mínimo de 1 KiB e `Vary: Accept-Encoding`.

Não adiciona cache de páginas ou de respostas da API, não altera validade de arquivos no navegador e não muda sessões, limites PHP ou configurações do banco. Atualizações de arquivos continuam visíveis normalmente. Configurações específicas dos virtual hosts podem substituir os padrões.

Antes de aplicar manualmente, guarde a configuração anterior, rode `nginx -t` e recarregue o Nginx somente se a validação passar. Em instalações personalizadas, confira se as diretivas já foram definidas no mesmo contexto para evitar duplicidade.

Valide tamanho transferido com `Accept-Encoding: gzip` e sem compressão, além da igualdade do conteúdo descomprimido. Tempo total de carregamento também depende da rede, imagens, scripts de terceiros e das consultas realizadas pela aplicação; redução de bytes não equivale à mesma porcentagem de redução de tempo.

Referência: https://nginx.org/en/docs/http/ngx_http_gzip_module.html
