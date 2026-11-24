<?php
require_once __DIR__.'/access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!radius_has_permission($conn,$radiusUser,'perm_clientes')) { http_response_code(403); exit('{}'); }
if (empty($_SESSION['MKA_Usuario']) && empty($_SESSION['MM_Usuario'])) { http_response_code(403); exit('{}'); }
if (empty($_SESSION['radius_preferences_csrf'])) $_SESSION['radius_preferences_csrf']=bin2hex(random_bytes(32));
$method=$_SERVER['REQUEST_METHOD'];
if ($method==='POST') {
    $input=json_decode(file_get_contents('php://input',false,null,0,4096),true);
    if (!is_array($input) || !isset($input['csrf']) || !is_string($input['csrf']) || !hash_equals($_SESSION['radius_preferences_csrf'],$input['csrf'])) { http_response_code(403); exit('{}'); }
    if (!isset($input['hide_disabled']) || !is_bool($input['hide_disabled'])) { http_response_code(422); exit('{}'); }
    $value=$input['hide_disabled'] ? 'sim' : 'nao';
    $stmt=$conn->prepare("INSERT INTO sis_opcao (nome,valor) VALUES ('radius_hide_disabled',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    if (!$stmt) { http_response_code(503); exit('{}'); }
    $stmt->bind_param('s',$value);
    if (!$stmt->execute()) { http_response_code(503); exit('{}'); }
} elseif ($method!=='GET') { http_response_code(405); exit('{}'); }
$result=$conn->query("SELECT valor FROM sis_opcao WHERE nome='radius_hide_disabled' LIMIT 1");
if (!$result) { http_response_code(503); exit('{}'); }
$row=$result->fetch_assoc();
echo json_encode(array('ok'=>true,'hide_disabled'=>$row ? $row['valor']==='sim' : true,'csrf'=>$_SESSION['radius_preferences_csrf']));
