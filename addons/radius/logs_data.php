<?php
include('addons.class.php');
require_once('radius_lib.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$filter = radius_normalize_filter(
    isset($_GET['filtro']) ? (string)$_GET['filtro'] : 'todos',
    'todos'
);
$linesLimit = radius_normalize_lines(
    isset($_GET['linhas']) ? (int)$_GET['linhas'] : 100,
    100
);
$data = radius_read_logs($filter, $linesLimit);

echo json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
