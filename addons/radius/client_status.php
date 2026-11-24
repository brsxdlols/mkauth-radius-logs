<?php
require_once __DIR__.'/access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!radius_has_permission($conn,$radiusUser,'perm_clientes')) { http_response_code(403); exit('{}'); }
$user=(string)($_SESSION['MKA_Usuario'] ?? $_SESSION['MM_Usuario'] ?? '');
$stmt=$conn->prepare('SELECT cli_grupos FROM sis_acesso WHERE login=? LIMIT 1');
$stmt->bind_param('s',$user);$stmt->execute();$access=$stmt->get_result()->fetch_assoc();
if (!$access) { http_response_code(403); exit('{}'); }
$groups=array_map('trim',explode(',',(string)$access['cli_grupos']));
$full=trim((string)$access['cli_grupos'])==='' || in_array('full_clientes',$groups,true);
session_write_close();
$input=json_decode(file_get_contents('php://input',false,null,0,150000),true);
if (!is_array($input) || !isset($input['logins']) || !is_array($input['logins']) || count($input['logins'])>2000) { http_response_code(422); exit('{}'); }
foreach($input['logins'] as $value) if(!is_string($value) || strlen($value)>64){http_response_code(422);exit('{}');}
require_once __DIR__.'/client_links.php';
$clients=radius_live_clients($conn,$input['logins'],$full,$groups);
$statuses=array();
foreach($clients as $key=>$client)$statuses[$key]=array('disabled'=>$client['disabled'],'blocked'=>$client['blocked'],'missing_pages'=>$client['missing_pages']);
echo json_encode((object)$statuses,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
