<?php
include('addons.class.php');
require_once('radius_lib.php');

$login = isset($_GET['login']) ? trim((string)$_GET['login']) : '';
$targetUrl = radius_client_search_url($login);

if ($login !== '' && strlen($login) <= 64) {
    require_once('/opt/mk-auth/include/conexao.php');

    if (isset($LOADMYSQL) && $LOADMYSQL instanceof mysqli) {
        $statement = $LOADMYSQL->prepare('SELECT 1 FROM sis_cliente WHERE login = ? LIMIT 1');
        if ($statement) {
            $statement->bind_param('s', $login);
            if ($statement->execute()) {
                $statement->store_result();
                if ($statement->num_rows > 0) {
                    $targetUrl = radius_client_connections_url($login);
                }
            }
            $statement->close();
        }
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $targetUrl, true, 302);
exit;
