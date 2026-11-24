<?php
require_once __DIR__.'/access.php';require_once __DIR__.'/connection_settings_model.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function connection_reply($code,$data){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$operator=(string)(!empty($_SESSION['MKA_Usuario'])?$_SESSION['MKA_Usuario']:($_SESSION['MM_Usuario']??''));$scope=radius_client_scope($conn,$operator);
$global=$scope==='1=1'&&radius_has_permission($conn,$operator,'perm_config');$client=$global||radius_has_permission($conn,$operator,'perm_altcliente');
if(!$client)connection_reply(403,array('error'=>'Sem permissão para alterar conexões.'));
if(empty($_SESSION['suite_connection_csrf']))$_SESSION['suite_connection_csrf']=bin2hex(random_bytes(24));$csrf=$_SESSION['suite_connection_csrf'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&isset($_GET['q'])){
 if(!is_string($_GET['q']))connection_reply(422,array('error'=>'Busca inválida.'));
 $term=trim($_GET['q']);if(strlen($term)<2||strlen($term)>120)connection_reply(200,array('clients'=>array()));
 session_write_close();$conn->set_charset('utf8mb4');
 try{$like='%'.strtr($term,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';$q=$conn->prepare("SELECT c.nome,c.login FROM sis_cliente c WHERE ($scope) AND (c.nome LIKE ? ESCAPE '!' OR c.login LIKE ? ESCAPE '!') ORDER BY c.nome,c.login LIMIT 15");$q->bind_param('ss',$like,$like);$q->execute();connection_reply(200,array('clients'=>$q->get_result()->fetch_all(MYSQLI_ASSOC)));}catch(Throwable $e){error_log('Connection search: '.$e->getMessage());connection_reply(503,array('error'=>'Não foi possível buscar os cadastros.'));}
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
 $selected=isset($_GET['login'])&&is_string($_GET['login'])?trim($_GET['login']):null;
 if($selected!==null&&($selected===''||strlen($selected)>64))connection_reply(422,array('error'=>'Login inválido.'));
 try{connection_reply(200,array('csrf'=>$csrf,'can_global'=>$global,'current'=>mka_connection_settings_current($conn,$scope,$selected)));}catch(DomainException $e){connection_reply(404,array('error'=>$e->getMessage()));}catch(Throwable $e){connection_reply(503,array('error'=>'Não foi possível consultar a situação atual.'));}
}
if($_SERVER['REQUEST_METHOD']!=='POST')connection_reply(405,array('error'=>'Método não permitido.'));
foreach($_POST as $value)if(!is_string($value))connection_reply(422,array('error'=>'Dados inválidos.'));
if(!hash_equals($csrf,$_POST['csrf']??''))connection_reply(403,array('error'=>'Sessão expirada. Reabra a ferramenta.'));
try{
 $conn->set_charset('utf8mb4');$action=$_POST['action']??'';
 if($action==='preview'){
  $selection=$_POST['selection']??'client';if(!in_array($selection,array('all','client'),true))throw new InvalidArgumentException('Escopo inválido.');if($selection==='all'&&!$global)connection_reply(403,array('error'=>'Operação global requer permissão de configuração e acesso a todos os grupos.'));
  $login=$selection==='all'?null:trim($_POST['login']??'');if($login!==null&&($login===''||strlen($login)>64))throw new InvalidArgumentException('Informe o login do cadastro principal.');
  $sim=($_POST['operation']??'')==='simultaneous';if($sim&&!$global)connection_reply(403,array('error'=>'Acessos simultâneos requerem permissão de configuração e acesso a todos os grupos.'));if($sim){$value=$_POST['simultaneous']??'';if(!in_array($value,array('sim','nao'),true))throw new InvalidArgumentException('Escolha Sim ou Não.');$options=array('simultaneous'=>$value);$rows=mka_simultaneous_rows($conn,$scope,$login);}else{$options=mka_connection_settings_options($_POST);$rows=mka_connection_settings_rows($conn,$scope,$login);}if(!$rows)throw new DomainException('Nenhum cadastro disponível.');$changed=0;foreach($rows as $row)if($sim?$row['simultaneo']!==$value:mka_connection_settings_changed($row,$options))$changed++;
  $preview=array('key'=>bin2hex(random_bytes(24)),'snapshot'=>mka_connection_settings_snapshot($rows),'login'=>$login,'options'=>$options,'created'=>time());$_SESSION['suite_connection_preview']=$preview;
  connection_reply(200,array('preview_key'=>$preview['key'],'matched'=>count($rows),'changed'=>$changed,'selection'=>$selection,'login'=>$login,'options'=>$options,'current'=>$sim?null:mka_connection_settings_current($conn,$scope,$login),'current_mac'=>$login!==null&&!$sim&&count($rows)===1?trim((string)$rows[0]['mac']):null));
 }
 if($action==='execute'){
  $preview=$_SESSION['suite_connection_preview']??null;
  if(!$preview||!hash_equals($preview['key'],$_POST['preview_key']??'')||time()-$preview['created']>900)throw new UnexpectedValueException('Prévia expirada. Gere uma nova prévia.');
  if(($_POST['confirm']??'')!=='1')throw new InvalidArgumentException('Confirme a prévia para executar.');if($preview['login']===null&&!$global)connection_reply(403,array('error'=>'Sem permissão para operação global.'));
  if(isset($preview['options']['simultaneous'])&&!$global)connection_reply(403,array('error'=>'Sem permissão para alterar acessos simultâneos.'));mka_connection_settings_schema($conn);$result=isset($preview['options']['simultaneous'])?mka_simultaneous_execute($conn,$operator,$scope,$preview):mka_connection_settings_execute($conn,$operator,$scope,$preview);connection_reply(200,array('ok'=>true)+$result);
 }
 throw new InvalidArgumentException('Operação inválida.');
}catch(UnexpectedValueException $e){connection_reply(409,array('error'=>$e->getMessage()));}catch(InvalidArgumentException $e){connection_reply(422,array('error'=>$e->getMessage()));}catch(DomainException $e){connection_reply(403,array('error'=>$e->getMessage()));}catch(Throwable $e){error_log('Connection settings: '.$e->getMessage());connection_reply(503,array('error'=>'Não foi possível aplicar as alterações.'));}
