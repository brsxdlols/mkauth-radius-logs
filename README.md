# MK-Auth Radius Logs

Addon para acompanhar o log do FreeRADIUS dentro do painel administrativo do MK-Auth.

## Recursos

- painel responsivo com contadores de conexões, erros, duplicidades e alertas SQL;
- filtros por tipo de evento e seleção de 50 a 2.000 linhas;
- pesquisa instantânea por login, NAS, MAC ou mensagem;
- seleção de NAS combinada com a pesquisa e preservada nas atualizações AJAX;
- painel de eventos em estilo terminal, com fundo preto e cores por tipo de log;
- início automático e rolagem até os eventos ao abrir o addon ou clicar em **Atualizar auto**;
- atualização AJAX a cada 5 segundos, sem recarregar a página;
- preservação da linha e da posição de rolagem durante a atualização;
- login clicável para a busca nativa de clientes do MK-Auth;
- saída do log protegida com escape de HTML;
- limpeza de sessões presas protegida por sessão administrativa, POST e token CSRF;
- conexão com o banco reutilizada do próprio MK-Auth, sem credenciais no addon;
- criação automática e idempotente do atalho no `addon.js`;
- detecção dos caminhos comuns do log e ajuste automático de leitura para o usuário web;
- backup automático antes da instalação ou atualização.

## Compatibilidade

- MK-Auth instalado em `/opt/mk-auth`;
- PHP 7.2 ou superior; validado em PHP 8.0.30;
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
/root/backups/mkauth-radius-logs-AAAAmmdd-HHMMSS-v4.3.6
```

## Instalação pelo GitHub

Depois que este repositório estiver publicado em `brsxdlols/mkauth-radius-logs`:

```sh
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-radius-logs/main/installers/github-install.sh | sh
```

## Rollback

Informe o diretório de backup criado pelo instalador:

```sh
sh installers/rollback.sh /root/backups/mkauth-radius-logs-AAAAmmdd-HHMMSS-v4.3.6
```

## Funcionamento da atualização

O navegador consulta `logs_data.php` a cada 5 segundos e substitui somente os eventos e contadores. A página completa, o menu do MK-Auth e os controles não são recarregados.

Durante a instalação, o script procura o `addon.js` usado pelo MK-Auth, remove somente registros `add_menu` equivalentes do Radius e grava um único atalho **Radius Logs** no menu **Provedor**. O arquivo original é incluído no backup antes da consolidação.

O instalador também procura os caminhos mais comuns do log do FreeRADIUS e testa a leitura como `www-data`. Quando necessário, concede acesso por ACL; em sistemas sem ACL, reutiliza de forma conservadora o grupo já associado ao arquivo. O addon escolhe automaticamente o primeiro arquivo de log acessível.

Quando a lista está rolada, o JavaScript registra a primeira linha visível e seu deslocamento. Depois de inserir os eventos novos, ele procura a mesma linha e restaura a posição. Se a lista estiver no topo, ela continua acompanhando os eventos mais recentes.

Ao abrir o addon diretamente pelo menu, a atualização automática começa e a página rola até o painel de eventos. Quando o operador clica em **Atualizar auto** depois de uma pausa, o POST inicia as consultas e rola novamente até os eventos. O botão **Pausar** interrompe as consultas sem forçar a rolagem.

O botão **Limpar sessões presas** exclui do `radacct` apenas registros sem `acctstoptime`. Ele corrige sessões que permaneceram abertas no banco, mas não envia comando de desconexão ao NAS.

## Segurança

- `index.php`, `logs_data.php` e `run_script.hhvm` carregam a autenticação nativa do MK-Auth.
- A limpeza exige token CSRF associado à sessão administrativa.
- Linhas do FreeRADIUS são inseridas no DOM como texto, não como HTML.
- O addon não contém senha do MySQL. A limpeza usa `/opt/mk-auth/include/conexao.php`.

## Estrutura

```text
addons/radius/       Arquivos instalados no MK-Auth
installers/          Instalação, instalação remota e rollback
VERSION              Versão do pacote
```
