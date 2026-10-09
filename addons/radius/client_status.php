<?php
include('addons.class.php');
require_once('/opt/mk-auth/include/conexao.php');
require_once('client_links.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '{}';
    exit;
}

if (!isset($LOADMYSQL) || !($LOADMYSQL instanceof mysqli)) {
    http_response_code(500);
    echo '{}';
    exit;
}

$scope = radius_current_access_scope($LOADMYSQL);
if (!$scope) {
    http_response_code(403);
    echo '{}';
    exit;
}

session_write_close();
$input = json_decode(file_get_contents('php://input', false, null, 0, 150000), true);
if (!is_array($input) || !isset($input['logins']) || !is_array($input['logins']) || count($input['logins']) > 2000) {
    http_response_code(422);
    echo '{}';
    exit;
}

foreach ($input['logins'] as $value) {
    if (!is_string($value) || strlen($value) > 64) {
        http_response_code(422);
        echo '{}';
        exit;
    }
}

$clients = radius_live_clients($LOADMYSQL, $input['logins'], $scope['full'], $scope['groups']);
$statuses = array();
foreach ($clients as $key => $client) {
    $statuses[$key] = array(
        'disabled' => $client['disabled'],
        'blocked' => $client['blocked'],
        'missing_pages' => $client['missing_pages'],
    );
}

echo json_encode((object)$statuses, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
