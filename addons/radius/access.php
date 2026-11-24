<?php
// Native admin identity and database only; no dashboard/busca dependency.
require_once __DIR__.'/client_links.php';
function radius_has_permission($db,$user,$permission){
    if($user==='')return false;
    $s=$db->prepare("SELECT 1 FROM sis_perm WHERE usuario=? AND nome=? AND permissao='sim' LIMIT 1");
    if(!$s)return false;$s->bind_param('ss',$user,$permission);
    if(!$s->execute())return false;return (bool)$s->get_result()->fetch_row();
}
function radius_client_scope($db,$user){
    $access=radius_current_access_scope($db);if(!$access)return '1=0';
    if($access['full'])return '1=1';$quoted=array();
    foreach($access['groups'] as $group)if($group!==''&&$group!=='ped_fil')$quoted[]="'".$db->real_escape_string($group)."'";
    return $quoted?'c.grupo IN ('.implode(',',$quoted).')':'1=0';
}
if(defined('RADIUS_ACCESS_TEST'))return;
$radiusCwd=getcwd();chdir(__DIR__);ob_start();http_response_code(403);
require_once __DIR__.'/addons.class.php';
if(session_status()===PHP_SESSION_NONE){
    $names=array();foreach(array_keys($_COOKIE)as $n)if(preg_match('/^_admin-[a-f0-9]{40}-MKA$/D',$n))$names[]=$n;
    if(count($names)===1)session_name($names[0]);
    elseif(count($names)===0&&isset($_COOKIE['mka']))session_name('mka');
    else{ob_end_clean();chdir($radiusCwd);exit('Sessão administrativa necessária.');}
    session_start();
}
$radiusUser=(string)(!empty($_SESSION['MKA_Usuario'])?$_SESSION['MKA_Usuario']:($_SESSION['MM_Usuario']??''));
if($radiusUser===''||(empty($_SESSION['MKA_Logado'])&&empty($_SESSION['MM_Usuario']))){ob_end_clean();chdir($radiusCwd);exit('Acesso negado.');}
require_once dirname(__DIR__,3).'/include/conexao.php';
ob_end_clean();chdir($radiusCwd);
if(!isset($LOADMYSQL)||!($LOADMYSQL instanceof mysqli)){http_response_code(503);exit('Banco de dados indisponível.');}
$conn=$LOADMYSQL;
if(!radius_has_permission($conn,$radiusUser,'perm_clientes')||!radius_current_access_scope($conn)){http_response_code(403);exit('Sem permissão para consultar clientes.');}
http_response_code(200);header('Cache-Control: no-store, private');
