<?php
include('addons.class.php');
require_once('/opt/mk-auth/include/conexao.php');
require_once('radius_lib.php');
require_once('client_links.php');

if (!isset($LOADMYSQL) || !($LOADMYSQL instanceof mysqli)) {
    http_response_code(500);
    exit('Banco de dados indisponível.');
}

$login = isset($_GET['login']) ? trim((string)$_GET['login']) : '';
if (strlen($login) > 64) {
    http_response_code(422);
    exit('Login inválido.');
}

$scope = radius_current_access_scope($LOADMYSQL);
if (!$scope) {
    http_response_code(403);
    exit('Acesso negado.');
}

if (!$scope['full']) {
    if ($login === '') {
        http_response_code(403);
        exit('O monitor geral exige acesso a todos os clientes.');
    }
    $allowed = radius_live_clients($LOADMYSQL, array($login), false, $scope['groups']);
    if (!isset($allowed[strtolower($login)])) {
        http_response_code(403);
        exit('Cliente fora dos grupos permitidos.');
    }
}

session_write_close();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function radius_live_type($entry)
{
    if ($entry['type'] === 'outros'
        && preg_match('/rlm_sql|\(sql\)|connections to reach .*spares/i', $entry['line'])) {
        return 'sql';
    }
    return $entry['type'];
}

if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $data = radius_read_logs('todos', 2000);
    $rows = array();
    $allowedLimits = array(100, 250, 500, 1000, 2000);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if (!in_array($limit, $allowedLimits, true)) {
        $limit = 100;
    }

    $selectedTypes = isset($_GET['types'])
        ? explode(',', (string)$_GET['types'])
        : array('conectados', 'erros', 'multiplos');
    $validTypes = array('conectados', 'erros', 'multiplos', 'sql', 'outros');
    $selectedTypes = array_values(array_intersect($selectedTypes, $validTypes));

    foreach ($data['entries'] as $entry) {
        $type = radius_live_type($entry);
        if ($login !== '' && $entry['login'] !== $login) {
            continue;
        }
        if ($login === '' && !in_array($type, $selectedTypes, true)) {
            continue;
        }

        $labels = radius_type_labels();
        $rows[] = array(
            'type' => $type,
            'label' => $labels[$type],
            'login' => $entry['login'],
            'line' => $entry['line'],
        );
        if (count($rows) >= $limit) {
            break;
        }
    }

    $history = null;
    if ($login !== '' && isset($_GET['history']) && count($rows) === 0) {
        require_once('history.php');
        $history = radius_latest_history($login);
    }

    $logins = array_column($rows, 'login');
    if ($history && !empty($history['row'])) {
        $logins[] = $history['row']['login'];
    }
    $clients = radius_live_clients($LOADMYSQL, $logins, $scope['full'], $scope['groups']);

    foreach ($rows as &$row) {
        $key = strtolower(trim($row['login']));
        $match = isset($clients[$key]) ? $clients[$key] : array();
        $row['name'] = isset($match['name']) ? $match['name'] : '';
        $row['client_url'] = isset($match['url']) ? $match['url'] : '';
    }
    unset($row);

    if ($history && !empty($history['row'])) {
        $key = strtolower(trim($history['row']['login']));
        $match = isset($clients[$key]) ? $clients[$key] : array();
        $history['row']['name'] = isset($match['name']) ? $match['name'] : '';
        $history['row']['client_url'] = isset($match['url']) ? $match['url'] : '';
    }

    echo json_encode(array(
        'rows' => $rows,
        'history' => $history,
        'error' => $data['error'],
        'updated_at' => $data['updated_at'],
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log RADIUS ao vivo</title>
    <link rel="stylesheet" href="radius.css?v=440">
    <style>
        html { height: 100%; overflow: hidden; }
        body { box-sizing: border-box; height: 100%; display: flex; flex-direction: column; overflow: hidden; gap: 6px; padding: 10px 14px; margin: 0; background: #f3f7fc; color: #17324f; font: 14px system-ui, sans-serif; }
        body > * { flex-shrink: 0; }
        .live-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .live-heading h1 { margin: 0; font-size: 18px; }
        button { padding: 7px 12px; border: 1px solid #b8cbe4; border-radius: 8px; background: #fff; cursor: pointer; }
        #toggle { background: #e3344f; border-color: #e3344f; color: #fff; font-weight: 600; }
        #toggle.is-paused { background: #2563eb; border-color: #2563eb; }
        #status, #historyNote, .live-help { margin: 0; color: #64748b; font-size: 11px; }
        #historyNote:empty, .live-login .live-help { display: none; }
        .live-filters { display: flex; flex-wrap: wrap; gap: 6px; border: 0; padding: 0; margin: 0; }
        .live-filters legend { display: none; }
        .live-filters label { position: relative; cursor: pointer; }
        .live-filters input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .live-filters span { display: block; border: 1px solid #d9e3f1; border-radius: 8px; background: #fff; color: #475569; padding: 6px 10px; font-size: 11px; font-weight: 700; }
        .live-filters input:checked + span { background: #2563eb; border-color: #2563eb; color: #fff; }
        .live-filters input:focus-visible + span { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .live-search { display: flex; gap: 8px; }
        .live-search input { flex: 1; min-width: 0; }
        .live-search input, .live-search select { padding: 7px 9px; border: 1px solid #d7e2f0; border-radius: 8px; background: #fff; color: #17324f; font-size: 12px; }
        #logs { flex: 1 1 0; min-height: 0; max-height: none; overflow: auto; overscroll-behavior: contain; border-radius: 12px; }
        #logs [hidden] { display: none !important; }
    </style>
</head>
<body class="<?php echo $login === '' ? 'live-general' : 'live-login'; ?>">
    <header class="live-heading">
        <h1>Log RADIUS <?php echo $login !== '' ? '— ' . radius_escape($login) : '— geral'; ?></h1>
        <button id="toggle" type="button">Pausar</button>
    </header>

    <?php if ($login === '') { ?>
        <fieldset id="typeFilters" class="live-filters">
            <legend>Exibir eventos</legend>
            <label><input type="checkbox" value="conectados" checked><span>Login OK</span></label>
            <label><input type="checkbox" value="erros" checked><span>Login incorreto</span></label>
            <label><input type="checkbox" value="multiplos" checked><span>Duplicados</span></label>
            <label><input type="checkbox" value="sql"><span>SQL</span></label>
            <label><input type="checkbox" value="outros"><span>Informações</span></label>
        </fieldset>
    <?php } ?>

    <p id="status" role="status">Carregando…</p>
    <p id="historyNote"></p>
    <p class="live-help">Atualização a cada 3 segundos. Login OK histórico não comprova conexão atual.</p>
    <div class="live-search">
        <input id="search" type="search" aria-label="Buscar nome ou login" placeholder="Digite nome ou login para filtrar">
        <label>Linhas
            <select id="lineLimit">
                <option>100</option><option>250</option><option>500</option><option>1000</option><option>2000</option>
            </select>
        </label>
    </div>
    <main id="logs" class="radius-log-list"></main>

    <script src="compact_notice.js?v=440"></script>
    <script>
    (function () {
        var configuredLogin = <?php echo json_encode($login, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var isGeneral = configuredLogin === '';
        var running = true;
        var timer = null;
        var controller = null;
        var requestTimeout = null;
        var busy = false;
        var firstRequest = true;
        var savedRows = [];
        var toggle = document.getElementById('toggle');
        var status = document.getElementById('status');
        var logs = document.getElementById('logs');
        var filters = document.getElementById('typeFilters');
        var search = document.getElementById('search');
        var lineLimit = document.getElementById('lineLimit');

        function selectedTypes() {
            var result = [];
            var checked = filters ? filters.querySelectorAll('input:checked') : [];
            for (var index = 0; index < checked.length; index++) {
                result.push(checked[index].value);
            }
            return result.join(',');
        }

        function applySearch() {
            var query = search.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
            var children = logs.children;
            for (var index = 0; index < children.length; index++) {
                var content = children[index].textContent.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                children[index].hidden = content.indexOf(query) === -1;
            }
        }

        function render(rows) {
            var fragment = document.createDocumentFragment();
            for (var index = 0; index < rows.length; index++) {
                var row = rows[index];
                var entry = document.createElement('article');
                var meta = document.createElement('div');
                var badge = document.createElement('span');
                var client = document.createElement(row.client_url ? 'a' : (row.login ? 'button' : 'span'));
                var code = document.createElement('code');

                entry.className = 'radius-log-entry type-' + row.type;
                meta.className = 'radius-log-meta';
                badge.className = 'radius-log-badge';
                badge.textContent = row.label;
                client.className = 'radius-client-link';
                client.textContent = row.name ? row.name + ' • ' + row.login : row.login;
                if (row.client_url) {
                    client.href = row.client_url;
                    client.target = '_blank';
                    client.rel = 'noopener noreferrer';
                } else if (row.login) {
                    client.type = 'button';
                    client.style.cssText = 'border:0;background:transparent;padding:0;text-align:left;font:inherit;color:inherit;cursor:pointer';
                    client.onclick = function () {
                        window.mkaCompactNotice('Login RADIUS', 'Usuário não encontrado no sistema ou tentativa incorreta');
                    };
                }
                code.textContent = row.line;
                meta.appendChild(badge);
                meta.appendChild(client);
                entry.appendChild(meta);
                entry.appendChild(code);
                fragment.appendChild(entry);
            }
            while (logs.firstChild) {
                logs.removeChild(logs.firstChild);
            }
            logs.appendChild(fragment);
            applySearch();
        }

        function schedule() {
            window.clearTimeout(timer);
            if (running && !document.hidden) {
                timer = window.setTimeout(update, 3000);
            }
        }

        function update() {
            var url;
            if (!running || document.hidden || busy) {
                schedule();
                return;
            }
            busy = true;
            controller = window.AbortController ? new AbortController() : null;
            if (controller) {
                requestTimeout = window.setTimeout(function () {
                    controller.abort();
                }, 8000);
            }
            url = 'live.php?data=1&limit=' + encodeURIComponent(lineLimit.value)
                + (firstRequest ? '&history=1' : '')
                + (isGeneral ? '&types=' + encodeURIComponent(selectedTypes()) : '')
                + '&login=' + encodeURIComponent(configuredLogin);

            window.fetch(url, {
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Acesso indisponível (' + response.status + ')');
                }
                return response.json();
            }).then(function (data) {
                firstRequest = false;
                if (data.history) {
                    document.getElementById('historyNote').textContent = data.history.note;
                    if (data.history.row) {
                        savedRows = [data.history.row];
                    }
                }
                if (isGeneral || data.rows.length > 0) {
                    savedRows = data.rows;
                }
                status.textContent = data.error || ('Atualizado em ' + data.updated_at + (savedRows.length ? '' : ' — aguardando registros.'));
                render(savedRows);
            }).catch(function (error) {
                status.textContent = 'Falha na atualização: ' + error.message;
            }).then(function () {
                window.clearTimeout(requestTimeout);
                busy = false;
                schedule();
            });
        }

        if (filters) {
            filters.addEventListener('change', function () {
                savedRows = [];
                render(savedRows);
                window.clearTimeout(timer);
                if (!busy) {
                    update();
                }
            });
        }
        search.addEventListener('input', applySearch);
        lineLimit.addEventListener('change', function () {
            window.clearTimeout(timer);
            if (!busy) {
                update();
            }
        });
        toggle.onclick = function () {
            running = !running;
            toggle.textContent = running ? 'Pausar' : 'Retomar';
            toggle.classList.toggle('is-paused', !running);
            window.clearTimeout(timer);
            if (running) {
                update();
            }
        };
        document.addEventListener('visibilitychange', function () {
            window.clearTimeout(timer);
            if (!document.hidden && running) {
                update();
            }
        });
        window.addEventListener('pagehide', function () {
            running = false;
            window.clearTimeout(timer);
            window.clearTimeout(requestTimeout);
            if (controller) {
                controller.abort();
            }
        });

        update();
    }());
    </script>
    <script src="client_status.js?v=440"></script>
</body>
</html>
