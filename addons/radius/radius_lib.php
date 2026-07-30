<?php

function radius_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function radius_allowed_filters()
{
    return array('todos', 'conectados', 'sql', 'erros', 'multiplos');
}

function radius_normalize_filter($value, $default)
{
    $filter = is_string($value) ? $value : '';
    if (in_array($filter, radius_allowed_filters(), true)) {
        return $filter;
    }

    return $default;
}

function radius_normalize_lines($value, $default)
{
    $lines = (int)$value;
    if ($lines <= 0) {
        $lines = (int)$default;
    }

    return max(25, min(2000, $lines));
}

function radius_log_type($line)
{
    if (stripos($line, 'Login OK:') !== false) {
        return 'conectados';
    }
    if (stripos($line, 'Login incorrect') !== false) {
        return 'erros';
    }
    if (stripos($line, 'Multiple logins') !== false) {
        return 'multiplos';
    }
    if (stripos($line, 'sql:') !== false) {
        return 'sql';
    }

    return 'outros';
}

function radius_extract_login($line)
{
    if (preg_match('/\[([^\/\]]+)\/[^\]]*\]/', $line, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function radius_extract_nas($line)
{
    if (preg_match('/\bfrom client\s+(.+?)\s+port\b/i', $line, $matches)) {
        return trim($matches[1]);
    }

    if (preg_match('/\bfrom client\s+([^\)\]]+)/i', $line, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function radius_type_labels()
{
    return array(
        'conectados' => 'Conectado',
        'sql' => 'SQL',
        'erros' => 'Login incorreto',
        'multiplos' => 'Duplicada',
        'outros' => 'Informação',
    );
}

function radius_filter_url($filter, $lines)
{
    return 'index.php?' . http_build_query(array(
        'filtro' => $filter,
        'linhas' => $lines,
    ));
}

function radius_client_url($login)
{
    return '/admin/clientes.hhvm?' . http_build_query(array(
        'busca' => $login,
        'campo' => 'login',
    ));
}

function radius_log_candidates()
{
    $candidates = array();
    $configuredPath = getenv('RADIUS_LOG_FILE');

    if (is_string($configuredPath) && $configuredPath !== '') {
        $candidates[] = $configuredPath;
    }

    $candidates[] = '/var/log/freeradius/radius.log';
    $candidates[] = '/var/log/freeradius/freeradius.log';
    $candidates[] = '/var/log/freeradius/radiusd.log';
    $candidates[] = '/var/log/radius/radius.log';
    $candidates[] = '/var/log/radius/radiusd.log';

    return array_values(array_unique($candidates));
}

function radius_log_path()
{
    $candidates = radius_log_candidates();

    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function radius_read_logs($filter, $linesLimit)
{
    $logPath = radius_log_path();
    $logLines = array();
    $logError = '';

    if (is_readable($logPath)) {
        $command = 'tail -n ' . (int)$linesLimit . ' ' . escapeshellarg($logPath) . ' 2>/dev/null';
        $result = shell_exec($command);
        if ($result === null) {
            $logError = 'Não foi possível executar a leitura do log.';
        } elseif ($result !== '') {
            $logLines = preg_split('/\r\n|\r|\n/', rtrim($result));
        }
    } else {
        $logError = 'O arquivo de log do FreeRADIUS não está acessível para leitura: ' . $logPath;
    }

    $counts = array(
        'todos' => 0,
        'conectados' => 0,
        'sql' => 0,
        'erros' => 0,
        'multiplos' => 0,
        'outros' => 0,
    );
    $entries = array();
    $labels = radius_type_labels();

    foreach (array_reverse($logLines) as $line) {
        if ($line === '') {
            continue;
        }

        $type = radius_log_type($line);
        $login = radius_extract_login($line);
        $nas = radius_extract_nas($line);
        $counts['todos']++;
        $counts[$type]++;

        if ($filter !== 'todos' && $filter !== $type) {
            continue;
        }

        $entries[] = array(
            'key' => sha1($line),
            'type' => $type,
            'label' => $labels[$type],
            'line' => $line,
            'login' => $login,
            'nas' => $nas,
            'client_url' => $login === '' ? '' : radius_client_url($login),
            'search' => strtolower($line . ' ' . $login . ' ' . $nas),
        );
    }

    return array(
        'updated_at' => date('d/m/Y H:i:s'),
        'counts' => $counts,
        'entries' => $entries,
        'error' => $logError,
    );
}
