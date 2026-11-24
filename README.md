# MK-Auth Radius Logs

Addon para acompanhar o log do FreeRADIUS dentro do painel administrativo do MK-Auth.

## Recursos

- painel responsivo com contadores de conexões, erros, duplicidades e alertas SQL;
- filtros combináveis por tipo de evento e seleção de 50 a 2.000 linhas;
- seleção inicial de Conectados, Incorretos e Duplicados, com SQL disponível sob demanda;
- pesquisa instantânea por login, NAS, MAC ou mensagem;
- seleção de NAS combinada com a pesquisa e preservada nas atualizações AJAX;
- painel de eventos em estilo terminal, com fundo preto e cores por tipo de log;
- início automático e rolagem até os eventos ao abrir o addon ou clicar em **Atualizar auto**;
- atualização AJAX a cada 2 segundos, sem recarregar a página;
- preservação da linha e da posição de rolagem durante a atualização;
- login clicável para abrir diretamente o relatório de conexões quando o cliente existir;
- pesquisa nativa por login como alternativa quando o cliente não for encontrado;
- senhas presentes em linhas do FreeRADIUS ocultadas antes de chegar ao navegador;
- identificação de logins principais e adicionais pelo cliente proprietário;
- indicadores de cliente desativado ou bloqueado, respeitando os grupos permitidos ao operador;
- configuração persistente para ocultar logins de clientes desativados;
- monitor ao vivo em janela separada, com atualização a cada 3 segundos;
- busca histórica sob demanda no log atual e em até 14 rotações, limitada a 64 MB ou 2 segundos;
- saída do log protegida com escape de HTML;
- estado atual da conexão e últimos eventos de accounting;
- ferramentas de IP/MAC com prévia, confirmação, CSRF e permissões nativas;
- visualização de accounting somente leitura; limpeza de sessões desabilitada;
- conexão com o banco reutilizada do próprio MK-Auth, sem credenciais no addon;
- atalho **Provedor → LOG RADIUS**, com carregamento independente no script administrativo nativo e sem duplicações;
- detecção dos caminhos comuns do log e ajuste automático de leitura para o usuário web;
- backup automático antes da instalação ou atualização.

## Compatibilidade

- MK-Auth instalado em `/opt/mk-auth`;
- Python 3.7+ para instalação e PHP 7.2 ou superior; validado em PHP 8.0.30;
- FreeRADIUS com log em `/var/log/freeradius/radius.log`;
- servidor web executando como `www-data` ou com permissão equivalente de leitura do log.

O instalador não modifica tabelas nem executa a limpeza de sessões.

## Instalação a partir de um checkout

Execute como `root`:

```sh
sh installers/install.sh
```

O addon será instalado em:

```text
/opt/mk-auth/admin/addons/radius
```

Antes de substituir uma instalação existente, o instalador cria um backup em:

```text
/root/backups/mkauth-radius-logs-AAAAmmdd-HHMMSS-v4.5.0-XXXXXX
```

## Instalação pelo GitHub

Execute como root:

```sh
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-radius-logs/main/installers/github-install.sh | sh
```

## Rollback

Informe o diretório de backup criado pelo instalador:

```sh
sh installers/rollback.sh /root/backups/mkauth-radius-logs-AAAAmmdd-HHMMSS-v4.5.0-XXXXXX
```

## Funcionamento da atualização

O navegador consulta `logs_data.php` a cada 2 segundos e substitui somente os eventos e contadores. A página completa, o menu do MK-Auth e os controles não são recarregados. Uma nova consulta só é iniciada depois que a anterior termina.

Ao clicar em um login, `client_target.php` verifica o login exato em `sis_cliente`. Se o cliente existir, abre o relatório nativo `relatorios_u.hhvm`; se não existir, abre a busca de clientes preenchida com o login. A consulta acontece somente no clique, não durante as atualizações automáticas do log.

O botão **Monitor ao vivo** abre `live.php` em uma janela separada. O monitor geral mantém os filtros essenciais ativos e permite habilitar SQL e informações sob demanda. Quando aberto para um login específico, pode procurar o último evento também nos arquivos rotacionados, dentro dos limites seguros documentados acima.

`client_status.php` resolve logins principais e adicionais no banco do MK-Auth e retorna somente os indicadores necessários. O operador pode usar a engrenagem ao lado dos filtros para ocultar clientes desativados; a preferência é salva no MK-Auth e compartilhada pelos operadores autorizados.

Por padrão, **Conectados**, **Incorretos** e **Duplicados** iniciam selecionados; **Todos** e **SQL** começam desmarcados. Os quatro tipos podem ser combinados livremente, e o botão **Todos** restaura a exibição completa, incluindo eventos informativos. A escolha fica preservada na aba durante as atualizações AJAX e recarregamentos.

O instalador preserva os demais menus e instala o atalho **LOG RADIUS** em **Provedor** nos carregadores `addons/addon.js` e `scripts/mk-auth.js` (quando presente). Isso permite abrir o addon mesmo se um `addon.js` de terceiros falhar. Os arquivos originais ficam no backup.

O instalador também procura os caminhos mais comuns do log do FreeRADIUS e testa a leitura como `www-data`. Quando necessário, concede acesso por ACL; em sistemas sem ACL, reutiliza de forma conservadora o grupo já associado ao arquivo. O addon escolhe automaticamente o primeiro arquivo de log acessível.

Quando a lista está rolada, o JavaScript registra a primeira linha visível e seu deslocamento. Depois de inserir os eventos novos, ele procura a mesma linha e restaura a posição. Se a lista estiver no topo, ela continua acompanhando os eventos mais recentes.

Ao abrir o addon diretamente pelo menu, a atualização automática começa e a página rola até o painel de eventos. Quando o operador clica em **Atualizar auto** depois de uma pausa, o POST inicia as consultas e rola novamente até os eventos. O botão **Pausar** interrompe as consultas sem forçar a rolagem.

A limpeza de sessões foi removida. O endpoint legado responde HTTP 405 e não altera o `radacct`. As ferramentas de IP/MAC alteram somente os cadastros explicitamente confirmados pelo operador.

## Segurança

- `index.php`, `logs_data.php`, `client_target.php`, `client_status.php`, `live.php` e `run_script.hhvm` carregam a autenticação nativa do MK-Auth.
- Consultas de status e do monitor usam prepared statements e respeitam `cli_grupos` do operador.
- Credenciais que eventualmente apareçam no formato `[login/senha]` ou em atributos de senha são substituídas por `***` antes da saída.
- Preferências e ferramentas de conexão exigem token CSRF associado à sessão administrativa.
- Linhas do FreeRADIUS são inseridas no DOM como texto, não como HTML.
- O addon não contém senha do MySQL e reutiliza a conexão nativa. O log geral exige acesso a todos os grupos; o monitor por cliente respeita os grupos autorizados.

## Estrutura

```text
addons/radius/       Arquivos instalados no MK-Auth
installers/          Instalação, instalação remota e rollback
VERSION              Versão do pacote
```


## Fonte oficial e integração com a dashboard

Este repositório é a fonte oficial de todos os recursos do Log RADIUS. O pacote
4.5.0 funciona sozinho, sem instalar Dashboard, Busca Inteligente ou `addons/shared`.
A suíte `mkauth-dashboard-busca-suite` chama este instalador e valida
`package_features.json`; não aplica cópias próprias por cima dos arquivos Radius.
Correções de recursos e do atalho devem ser publicadas aqui. Alterações no contrato
do pacote também precisam passar pelos testes de integração da suíte.

O deploy valida PHP antes de trocar os arquivos e restaura addon/menus em caso de
falha. O rollback recusa menus modificados depois da instalação, para preservar
alterações de terceiros. Configurações e registros do banco não são revertidos.

## Testes

`python3 tests/test_source_contract.py` verifica o pacote sem acessar MK-Auth.
`MYSQL_TEST_PASSWORD=... python3 tests/test_standalone.py` requer PHP/mysqli e um
MySQL local de testes. Cria e remove apenas um schema aleatório de fixtures e um
servidor PHP em localhost; nunca usa tabelas nativas de produção.
