<?php

function radius_bind_dynamic_params($statement, $values)
{
    $types = str_repeat('s', count($values));
    $arguments = array($types);
    $index = 0;

    foreach ($values as $value) {
        $values[$index] = (string)$value;
        $arguments[] = &$values[$index];
        $index++;
    }

    return call_user_func_array(array($statement, 'bind_param'), $arguments);
}

function radius_current_access_scope($connection)
{
    $user = '';
    if (!empty($_SESSION['MKA_Usuario'])) {
        $user = (string)$_SESSION['MKA_Usuario'];
    } elseif (!empty($_SESSION['MM_Usuario'])) {
        $user = (string)$_SESSION['MM_Usuario'];
    }

    if ($user === '') {
        return null;
    }

    $statement = $connection->prepare('SELECT cli_grupos FROM sis_acesso WHERE login = ? LIMIT 1');
    if (!$statement) {
        return null;
    }

    $statement->bind_param('s', $user);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    $access = $result ? $result->fetch_assoc() : null;
    $statement->close();
    if (!$access) {
        return null;
    }

    $rawGroups = trim((string)$access['cli_grupos']);
    $groups = array_filter(array_map('trim', explode(',', $rawGroups)), 'strlen');

    return array(
        'full' => $rawGroups === '' || in_array('full_clientes', $groups, true),
        'groups' => array_values($groups),
    );
}

function radius_live_clients($connection, $logins, $fullAccess, $groups)
{
    $keys = array_values(array_unique(array_filter(array_map(function ($value) {
        return strtolower(trim((string)$value));
    }, $logins), 'strlen')));

    if (count($keys) === 0) {
        return array();
    }

    $marks = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT LOWER(TRIM(c.login)) AS login_key, c.nome, c.uuid_cliente, c.grupo, c.cli_ativado, c.bloqueado, c.pgcorte, c.pgaviso
        FROM sis_cliente c WHERE LOWER(TRIM(c.login)) IN ($marks)
        UNION
        SELECT LOWER(TRIM(a.username)) AS login_key, c.nome, c.uuid_cliente, c.grupo, c.cli_ativado, c.bloqueado, c.pgcorte, c.pgaviso
        FROM sis_adicional a
        INNER JOIN sis_cliente c ON LOWER(TRIM(a.login)) = LOWER(TRIM(c.login))
        WHERE LOWER(TRIM(a.username)) IN ($marks)";
    $parameters = array_merge($keys, $keys);
    $statement = $connection->prepare($sql);
    if (!$statement || !radius_bind_dynamic_params($statement, $parameters) || !$statement->execute()) {
        if ($statement) {
            $statement->close();
        }
        return array();
    }

    $result = $statement->get_result();
    $clients = array();
    $extension = is_file(__DIR__ . '/../../cliente_det.hhvm') ? 'hhvm' : 'php';

    while ($result && ($client = $result->fetch_assoc())) {
        if (!$fullAccess && !in_array((string)$client['grupo'], $groups, true)) {
            continue;
        }
        if (empty($client['uuid_cliente'])) {
            continue;
        }

        $clients[$client['login_key']] = array(
            'name' => $client['nome'],
            'disabled' => $client['cli_ativado'] === 'n',
            'blocked' => $client['bloqueado'] === 'sim',
            'missing_pages' => $client['bloqueado'] === 'sim'
                && ($client['pgcorte'] !== 'sim' || $client['pgaviso'] !== 'sim'),
            'url' => '/admin/cliente_det.' . $extension . '?uuid=' . rawurlencode($client['uuid_cliente']),
        );
    }

    $statement->close();
    return $clients;
}
