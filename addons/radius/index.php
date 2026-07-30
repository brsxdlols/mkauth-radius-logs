<?php
include('addons.class.php');
require_once('radius_lib.php');

$requestedFilter = isset($_REQUEST['filtro']) ? (string)$_REQUEST['filtro'] : '';
if (in_array($requestedFilter, radius_allowed_filters(), true)) {
    $_SESSION['radius_filtro'] = $requestedFilter;
}
$filter = radius_normalize_filter(
    isset($_SESSION['radius_filtro']) ? $_SESSION['radius_filtro'] : 'todos',
    'todos'
);

$requestedLines = isset($_REQUEST['linhas']) ? (int)$_REQUEST['linhas'] : 0;
if ($requestedLines > 0) {
    $_SESSION['radius_linhas'] = radius_normalize_lines($requestedLines, 100);
}
$linesLimit = radius_normalize_lines(
    isset($_SESSION['radius_linhas']) ? (int)$_SESSION['radius_linhas'] : 100,
    100
);

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$directAddonOpen = $requestMethod === 'GET'
    && !isset($_GET['filtro'])
    && !isset($_GET['linhas']);
$scrollToLogsOnLoad = $directAddonOpen
    || (isset($_POST['action']) && $_POST['action'] === 'start');
if ($directAddonOpen) {
    $_SESSION['radius_auto_refresh'] = true;
}

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'start') {
        $_SESSION['radius_auto_refresh'] = true;
    } elseif ($_POST['action'] === 'stop') {
        $_SESSION['radius_auto_refresh'] = false;
    }
}
$autoRefreshRunning = !empty($_SESSION['radius_auto_refresh']);

$csrfToken = isset($_SESSION['radius_csrf_token']) ? $_SESSION['radius_csrf_token'] : '';
if (!is_string($csrfToken) || strlen($csrfToken) < 32) {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['radius_csrf_token'] = $csrfToken;
}

$logData = radius_read_logs($filter, $linesLimit);
$counts = $logData['counts'];
$entries = $logData['entries'];
$logError = $logData['error'];
$nasOptions = array();
foreach ($entries as $entry) {
    if ($entry['nas'] !== '' && !in_array($entry['nas'], $nasOptions, true)) {
        $nasOptions[] = $entry['nas'];
    }
}
natcasesort($nasOptions);
$nasOptions = array_values($nasOptions);
$typeLabels = radius_type_labels();
$manifestName = isset($Manifest->name) ? $Manifest->name : 'Radius Logs';
$manifestVersion = isset($Manifest->version) ? $Manifest->version : '';
$htmlClass = isset($_SESSION['MM_Usuario']) ? '' : 'has-navbar-fixed-top';
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?php echo radius_escape($htmlClass); ?>">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title>MK-AUTH :: <?php echo radius_escape($manifestName); ?></title>
    <link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css">
    <link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css">
    <link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css">
    <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
    <link href="radius.css?v=435" rel="stylesheet" type="text/css">
    <script src="../../scripts/jquery.js"></script>
    <script src="../../scripts/mk-auth.js"></script>
</head>
<body>
<?php include('../../topo.php'); ?>

<main class="radius-app">
    <section class="radius-hero">
        <div>
            <div class="radius-eyebrow">Monitoramento em tempo real</div>
            <h1><i class="bi bi-activity"></i> <?php echo radius_escape($manifestName); ?></h1>
            <p>Autenticações do FreeRADIUS com acesso rápido à pesquisa de clientes.</p>
        </div>
        <div class="radius-version">v<?php echo radius_escape($manifestVersion); ?></div>
    </section>

    <section class="radius-summary" aria-label="Resumo das linhas carregadas">
        <div class="radius-stat radius-stat-neutral">
            <span>Linhas carregadas</span>
            <strong id="statTotal"><?php echo (int)$counts['todos']; ?></strong>
        </div>
        <div class="radius-stat radius-stat-success">
            <span>Conectados</span>
            <strong id="statConnected"><?php echo (int)$counts['conectados']; ?></strong>
        </div>
        <div class="radius-stat radius-stat-danger">
            <span>Incorretos</span>
            <strong id="statErrors"><?php echo (int)$counts['erros']; ?></strong>
        </div>
        <div class="radius-stat radius-stat-warning">
            <span>Duplicados</span>
            <strong id="statDuplicates"><?php echo (int)$counts['multiplos']; ?></strong>
        </div>
        <div class="radius-stat radius-stat-sql">
            <span>Alertas SQL</span>
            <strong id="statSql"><?php echo (int)$counts['sql']; ?></strong>
        </div>
    </section>

    <section class="radius-panel radius-controls">
        <div class="radius-control-row">
            <div>
                <span class="radius-label">Exibindo</span>
                <nav class="radius-filters" aria-label="Filtros dos logs">
                    <a class="<?php echo $filter === 'todos' ? 'active' : ''; ?>" href="<?php echo radius_escape(radius_filter_url('todos', $linesLimit)); ?>">Todos</a>
                    <a class="<?php echo $filter === 'conectados' ? 'active' : ''; ?>" href="<?php echo radius_escape(radius_filter_url('conectados', $linesLimit)); ?>">Conectados</a>
                    <a class="<?php echo $filter === 'erros' ? 'active' : ''; ?>" href="<?php echo radius_escape(radius_filter_url('erros', $linesLimit)); ?>">Incorretos</a>
                    <a class="<?php echo $filter === 'multiplos' ? 'active' : ''; ?>" href="<?php echo radius_escape(radius_filter_url('multiplos', $linesLimit)); ?>">Duplicados</a>
                    <a class="<?php echo $filter === 'sql' ? 'active' : ''; ?>" href="<?php echo radius_escape(radius_filter_url('sql', $linesLimit)); ?>">SQL</a>
                </nav>
            </div>

            <form class="radius-lines-form" method="get" action="index.php">
                <input type="hidden" name="filtro" value="<?php echo radius_escape($filter); ?>">
                <label for="linhas">Últimas linhas</label>
                <select name="linhas" id="linhas" onchange="this.form.submit()">
                    <?php foreach (array(50, 100, 250, 500, 1000, 2000) as $option) { ?>
                        <option value="<?php echo (int)$option; ?>" <?php echo $linesLimit === $option ? 'selected' : ''; ?>><?php echo (int)$option; ?></option>
                    <?php } ?>
                </select>
            </form>
        </div>

        <div class="radius-toolbar">
            <div class="radius-search">
                <i class="bi bi-search"></i>
                <input id="radiusSearch" type="search" placeholder="Filtrar nesta tela por login, NAS, MAC ou mensagem" autocomplete="off">
            </div>

            <div class="radius-nas-filter">
                <label for="radiusNasFilter">NAS</label>
                <select id="radiusNasFilter" aria-label="Filtrar eventos por NAS">
                    <option value="">Todos os NAS</option>
                    <?php foreach ($nasOptions as $nasOption) { ?>
                        <option value="<?php echo radius_escape($nasOption); ?>"><?php echo radius_escape($nasOption); ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="radius-actions">
                <form method="post" action="index.php">
                    <input type="hidden" name="filtro" value="<?php echo radius_escape($filter); ?>">
                    <input type="hidden" name="linhas" value="<?php echo (int)$linesLimit; ?>">
                    <?php if ($autoRefreshRunning) { ?>
                        <button class="radius-button radius-button-stop" type="submit" name="action" value="stop">
                            <i class="bi bi-pause-fill"></i> Pausar
                        </button>
                    <?php } else { ?>
                        <button class="radius-button radius-button-run" type="submit" name="action" value="start">
                            <i class="bi bi-play-fill"></i> Atualizar auto
                        </button>
                    <?php } ?>
                </form>
                <button id="refreshNowButton" class="radius-button radius-button-secondary" type="button">
                    <i class="bi bi-arrow-clockwise"></i> Atualizar agora
                </button>
                <button id="cleanSessionsButton" class="radius-button radius-button-clean" type="button" title="Remove do radacct os registros sem horário de encerramento. Não desconecta clientes no NAS.">
                    <i class="bi bi-trash"></i> Limpar sessões presas
                </button>
            </div>
        </div>

        <div class="radius-status-row">
            <span class="radius-status <?php echo $autoRefreshRunning ? 'is-running' : 'is-paused'; ?>">
                <span class="radius-status-dot"></span>
                <?php echo $autoRefreshRunning ? 'Atualização automática a cada 5 segundos' : 'Atualização automática pausada'; ?>
            </span>
            <span id="updatedAt">Atualizado em <?php echo radius_escape($logData['updated_at']); ?></span>
        </div>
    </section>

    <section class="radius-panel radius-log-panel">
        <header>
            <div>
                <span class="radius-label">Eventos</span>
                <h2 id="eventCount"><?php echo count($entries); ?> registro(s) no filtro atual</h2>
            </div>
            <span id="visibleCount" class="radius-visible-count"><?php echo count($entries); ?> visíveis</span>
        </header>

        <div id="radiusLogError" class="radius-empty radius-empty-error" <?php echo $logError === '' ? 'hidden' : ''; ?>>
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Falha ao ler o log</strong>
                <span id="radiusLogErrorText"><?php echo radius_escape($logError); ?></span>
        </div>
        <div id="radiusLogEmpty" class="radius-empty" <?php echo ($logError !== '' || count($entries) > 0) ? 'hidden' : ''; ?>>
                <i class="bi bi-inbox"></i>
                <strong>Nenhum evento encontrado</strong>
                <span>Tente outro filtro ou aumente a quantidade de linhas.</span>
        </div>
        <div id="radiusLogList" class="radius-log-list" <?php echo ($logError !== '' || count($entries) === 0) ? 'hidden' : ''; ?>>
                <?php foreach ($entries as $entry) {
                    $type = $entry['type'];
                    $login = $entry['login'];
                    ?>
                    <article class="radius-log-entry type-<?php echo radius_escape($type); ?>" data-key="<?php echo radius_escape($entry['key']); ?>" data-search="<?php echo radius_escape($entry['search']); ?>" data-nas="<?php echo radius_escape($entry['nas']); ?>">
                        <div class="radius-log-meta">
                            <span class="radius-log-badge"><?php echo radius_escape($typeLabels[$type]); ?></span>
                            <?php if ($login !== '') { ?>
                                <a class="radius-client-link" href="<?php echo radius_escape($entry['client_url']); ?>" target="_blank" rel="noopener" title="Pesquisar este login nos clientes do MK-Auth">
                                    <i class="bi bi-person-search"></i>
                                    <?php echo radius_escape($login); ?>
                                </a>
                            <?php } ?>
                        </div>
                        <code><?php echo radius_escape($entry['line']); ?></code>
                    </article>
                <?php } ?>
        </div>
        <div id="radiusNoSearchResults" class="radius-empty" hidden>
            <i class="bi bi-search"></i>
            <strong>Nenhuma linha corresponde à busca</strong>
            <span>Ajuste a pesquisa ou selecione outro NAS para mostrar os eventos.</span>
        </div>
    </section>
</main>

<?php include('../../baixo.hhvm'); ?>
<script src="../../menu.js.hhvm"></script>
<script>
(function () {
    var searchInput = document.getElementById('radiusSearch');
    var nasFilter = document.getElementById('radiusNasFilter');
    var list = document.getElementById('radiusLogList');
    var visibleCount = document.getElementById('visibleCount');
    var noResults = document.getElementById('radiusNoSearchResults');
    var emptyState = document.getElementById('radiusLogEmpty');
    var errorState = document.getElementById('radiusLogError');
    var errorText = document.getElementById('radiusLogErrorText');
    var cleanButton = document.getElementById('cleanSessionsButton');
    var refreshNowButton = document.getElementById('refreshNowButton');
    var autoRefreshRunning = <?php echo $autoRefreshRunning ? 'true' : 'false'; ?>;
    var scrollToLogsOnLoad = <?php echo $scrollToLogsOnLoad ? 'true' : 'false'; ?>;
    var refreshTimer = null;
    var refreshInFlight = false;
    var refreshInterval = 5000;
    var currentFilter = <?php echo json_encode($filter); ?>;
    var currentLines = <?php echo (int)$linesLimit; ?>;

    function setText(id, value) {
        var element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    function captureScrollAnchor() {
        var children;
        var index;
        var entry;

        if (!list) {
            return null;
        }
        if (list.scrollTop <= 8) {
            return { atTop: true, scrollTop: 0 };
        }

        children = list.getElementsByClassName('radius-log-entry');
        for (index = 0; index < children.length; index++) {
            entry = children[index];
            if (entry.offsetTop + entry.offsetHeight >= list.scrollTop) {
                return {
                    atTop: false,
                    key: entry.getAttribute('data-key'),
                    offset: entry.offsetTop - list.scrollTop,
                    scrollTop: list.scrollTop
                };
            }
        }

        return { atTop: false, key: '', offset: 0, scrollTop: list.scrollTop };
    }

    function restoreScrollAnchor(anchor) {
        var anchoredEntry;

        if (!list || !anchor) {
            return;
        }
        if (anchor.atTop) {
            list.scrollTop = 0;
            return;
        }

        if (anchor.key) {
            anchoredEntry = list.querySelector('[data-key="' + anchor.key + '"]');
        }
        if (anchoredEntry) {
            list.scrollTop = anchoredEntry.offsetTop - anchor.offset;
        } else {
            list.scrollTop = anchor.scrollTop;
        }
    }

    function createLogEntry(entry) {
        var article = document.createElement('article');
        var meta = document.createElement('div');
        var badge = document.createElement('span');
        var code = document.createElement('code');
        var clientLink;
        var clientIcon;

        article.className = 'radius-log-entry type-' + entry.type;
        article.setAttribute('data-key', entry.key);
        article.setAttribute('data-search', entry.search);
        article.setAttribute('data-nas', entry.nas || '');

        meta.className = 'radius-log-meta';
        badge.className = 'radius-log-badge';
        badge.textContent = entry.label;
        meta.appendChild(badge);

        if (entry.login !== '') {
            clientLink = document.createElement('a');
            clientLink.className = 'radius-client-link';
            clientLink.href = entry.client_url;
            clientLink.target = '_blank';
            clientLink.rel = 'noopener';
            clientLink.title = 'Pesquisar este login nos clientes do MK-Auth';

            clientIcon = document.createElement('i');
            clientIcon.className = 'bi bi-person-search';
            clientLink.appendChild(clientIcon);
            clientLink.appendChild(document.createTextNode(entry.login));
            meta.appendChild(clientLink);
        }

        code.textContent = entry.line;
        article.appendChild(meta);
        article.appendChild(code);

        return article;
    }

    function refreshNasOptions() {
        var selectedNas = nasFilter ? nasFilter.value : '';
        var entries = list ? list.getElementsByClassName('radius-log-entry') : [];
        var nasNames = [];
        var knownNas = {};
        var index;
        var nasName;
        var option;

        if (!nasFilter) {
            return;
        }

        for (index = 0; index < entries.length; index++) {
            nasName = entries[index].getAttribute('data-nas') || '';
            if (nasName !== '' && !Object.prototype.hasOwnProperty.call(knownNas, nasName)) {
                knownNas[nasName] = true;
                nasNames.push(nasName);
            }
        }

        nasNames.sort(function (first, second) {
            return first.toLowerCase().localeCompare(second.toLowerCase());
        });

        while (nasFilter.options.length > 0) {
            nasFilter.remove(0);
        }

        option = document.createElement('option');
        option.value = '';
        option.textContent = 'Todos os NAS';
        nasFilter.appendChild(option);

        if (selectedNas !== '' && !Object.prototype.hasOwnProperty.call(knownNas, selectedNas)) {
            nasNames.push(selectedNas);
        }

        for (index = 0; index < nasNames.length; index++) {
            option = document.createElement('option');
            option.value = nasNames[index];
            option.textContent = nasNames[index];
            nasFilter.appendChild(option);
        }

        nasFilter.value = selectedNas;
    }

    function applySearchFilter() {
        var term = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var selectedNas = nasFilter ? nasFilter.value : '';
        var entries = list ? list.getElementsByClassName('radius-log-entry') : [];
        var visible = 0;
        var index;
        var matches;
        var entryNas;

        for (index = 0; index < entries.length; index++) {
            entryNas = entries[index].getAttribute('data-nas') || '';
            matches = (term === '' || entries[index].getAttribute('data-search').indexOf(term) !== -1)
                && (selectedNas === '' || entryNas === selectedNas);
            entries[index].style.display = matches ? '' : 'none';
            if (matches) {
                visible++;
            }
        }

        if (visibleCount) {
            visibleCount.textContent = visible + ' visíveis';
        }
        if (noResults) {
            noResults.hidden = entries.length === 0 || visible !== 0;
        }
    }

    function renderPayload(payload) {
        var anchor = captureScrollAnchor();
        var fragment = document.createDocumentFragment();
        var index;
        var hasError = payload.error !== '';
        var hasEntries = payload.entries.length > 0;

        setText('statTotal', payload.counts.todos);
        setText('statConnected', payload.counts.conectados);
        setText('statErrors', payload.counts.erros);
        setText('statDuplicates', payload.counts.multiplos);
        setText('statSql', payload.counts.sql);
        setText('eventCount', payload.entries.length + ' registro(s) no filtro atual');
        setText('updatedAt', 'Atualizado em ' + payload.updated_at);

        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
        for (index = 0; index < payload.entries.length; index++) {
            fragment.appendChild(createLogEntry(payload.entries[index]));
        }
        list.appendChild(fragment);

        list.hidden = hasError || !hasEntries;
        errorState.hidden = !hasError;
        emptyState.hidden = hasError || hasEntries;
        if (hasError) {
            errorText.textContent = payload.error;
        }

        restoreScrollAnchor(anchor);
        refreshNasOptions();
        applySearchFilter();
    }

    function scheduleRefresh() {
        window.clearTimeout(refreshTimer);
        if (autoRefreshRunning) {
            refreshTimer = window.setTimeout(requestLogs, refreshInterval);
        }
    }

    function requestLogs() {
        if (refreshInFlight) {
            scheduleRefresh();
            return;
        }
        if (document.hidden && autoRefreshRunning) {
            scheduleRefresh();
            return;
        }

        refreshInFlight = true;
        window.clearTimeout(refreshTimer);

        jQuery.ajax({
            url: 'logs_data.php',
            type: 'GET',
            dataType: 'json',
            cache: false,
            data: {
                filtro: currentFilter,
                linhas: currentLines
            },
            success: function (payload) {
                renderPayload(payload);
            },
            error: function () {
                setText('updatedAt', 'Falha na atualização; tentando novamente...');
            },
            complete: function () {
                refreshInFlight = false;
                scheduleRefresh();
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applySearchFilter);
    }

    if (nasFilter) {
        nasFilter.addEventListener('change', applySearchFilter);
    }

    if (refreshNowButton) {
        refreshNowButton.addEventListener('click', requestLogs);
    }

    if (cleanButton) {
        cleanButton.addEventListener('click', function () {
            var confirmed = window.confirm(
                'Esta ação exclui do radacct os registros sem horário de encerramento (acctstoptime = NULL). Ela não desconecta o cliente no NAS. Deseja continuar?'
            );
            if (!confirmed) {
                return;
            }

            cleanButton.disabled = true;
            cleanButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Executando...';

            jQuery.ajax({
                url: 'run_script.hhvm',
                type: 'POST',
                data: {
                    csrf_token: <?php echo json_encode($csrfToken); ?>
                },
                success: function (response) {
                    window.alert(response);
                    cleanButton.disabled = false;
                    cleanButton.innerHTML = '<i class="bi bi-trash"></i> Limpar sessões presas';
                    requestLogs();
                },
                error: function () {
                    window.alert('Não foi possível executar a limpeza.');
                    cleanButton.disabled = false;
                    cleanButton.innerHTML = '<i class="bi bi-trash"></i> Limpar sessões presas';
                }
            });
        });
    }

    refreshNasOptions();
    applySearchFilter();
    scheduleRefresh();
    if (scrollToLogsOnLoad) {
        window.setTimeout(function () {
            var logPanel = document.querySelector('.radius-log-panel');
            if (logPanel) {
                logPanel.scrollIntoView({ block: 'start' });
            }
        }, 180);
    }
}());
</script>
</body>
</html>
