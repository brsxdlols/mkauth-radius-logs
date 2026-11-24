<?php
function mka_connection_settings_options($input){
 $result=array('clear'=>$input['clear']??'none','autoip'=>$input['autoip']??'keep','automac'=>$input['automac']??'keep');
 if(!in_array($result['clear'],array('none','ip','mac','both'),true)||!in_array($result['autoip'],array('keep','sim','nao'),true)||!in_array($result['automac'],array('keep','sim','nao'),true))throw new InvalidArgumentException('Opções inválidas.');
 if($result===array('clear'=>'none','autoip'=>'keep','automac'=>'keep'))throw new InvalidArgumentException('Escolha pelo menos uma alteração.');return $result;
}
function mka_connection_settings_rows($db,$scope,$login=null,$lock=false){
 $sql="SELECT c.id,c.login,c.ip,c.mac,c.autoip,c.automac FROM sis_cliente c WHERE ($scope)".($login!==null?' AND c.login=?':'').' ORDER BY c.id'.($lock?' FOR UPDATE':'');
 $q=$db->prepare($sql);if($login!==null)$q->bind_param('s',$login);$q->execute();return $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
function mka_connection_settings_changed($row,$options){
 return (in_array($options['clear'],array('ip','both'),true)&&trim((string)$row['ip'])!=='')||(in_array($options['clear'],array('mac','both'),true)&&trim((string)$row['mac'])!=='')||($options['autoip']!=='keep'&&$row['autoip']!==$options['autoip'])||($options['automac']!=='keep'&&$row['automac']!==$options['automac']);
}
function mka_connection_settings_snapshot($rows){return hash('sha256',json_encode($rows,JSON_INVALID_UTF8_SUBSTITUTE));}
function mka_connection_settings_schema($db){
 $db->query("CREATE TABLE IF NOT EXISTS suite_connection_operations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operator VARCHAR(64) NOT NULL,request_key VARCHAR(96) NOT NULL,selection VARCHAR(16) NOT NULL,client_login VARCHAR(64) NULL,options_json TEXT NOT NULL,changed_count INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY once_only(operator,request_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->query("CREATE TABLE IF NOT EXISTS suite_connection_operation_items(operation_id BIGINT UNSIGNED NOT NULL,client_id INT NOT NULL,login VARCHAR(64) NOT NULL,previous_ip VARCHAR(64) NULL,previous_mac VARCHAR(64) NULL,previous_autoip VARCHAR(8) NULL,previous_automac VARCHAR(8) NULL,PRIMARY KEY(operation_id,client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function mka_connection_settings_execute($db,$operator,$scope,$preview){
 $db->begin_transaction();try{
  $q=$db->prepare('SELECT id,changed_count FROM suite_connection_operations WHERE operator=? AND request_key=? LIMIT 1');$q->bind_param('ss',$operator,$preview['key']);$q->execute();$old=$q->get_result()->fetch_assoc();if($old){$db->commit();return array('operation_id'=>(int)$old['id'],'changed'=>(int)$old['changed_count']);}
  $rows=mka_connection_settings_rows($db,$scope,$preview['login'],true);
  if(!hash_equals($preview['snapshot'],mka_connection_settings_snapshot($rows)))throw new UnexpectedValueException('Os cadastros mudaram desde a prévia. Gere uma nova prévia.');
  $options=$preview['options'];$changed=array_values(array_filter($rows,function($row)use($options){return mka_connection_settings_changed($row,$options);}));$total=count($changed);$json=json_encode($options);$selection=$preview['login']===null?'all':'client';
  $q=$db->prepare('INSERT INTO suite_connection_operations(operator,request_key,selection,client_login,options_json,changed_count) VALUES(?,?,?,?,?,?)');$q->bind_param('sssssi',$operator,$preview['key'],$selection,$preview['login'],$json,$total);$q->execute();$operation=(int)$db->insert_id;
  $insert=$db->prepare('INSERT INTO suite_connection_operation_items(operation_id,client_id,login,previous_ip,previous_mac,previous_autoip,previous_automac) VALUES(?,?,?,?,?,?,?)');
  foreach($changed as $row){$insert->bind_param('iisssss',$operation,$row['id'],$row['login'],$row['ip'],$row['mac'],$row['autoip'],$row['automac']);$insert->execute();}
  if($total){$sets=array();if(in_array($options['clear'],array('ip','both'),true))$sets[]='ip=NULL';if(in_array($options['clear'],array('mac','both'),true))$sets[]='mac=NULL';foreach(array('autoip','automac') as $field)if($options[$field]!=='keep')$sets[]=$field."='".$options[$field]."'";$ids=implode(',',array_map(function($row){return (int)$row['id'];},$changed));$db->query('UPDATE sis_cliente SET '.implode(',',$sets).' WHERE id IN ('.$ids.')');}
  $db->commit();return array('operation_id'=>$operation,'changed'=>$total);
 }catch(Throwable $e){$db->rollback();throw $e;}
}

function mka_simultaneous_rows($db,$scope,$login=null,$lock=false){
 $result=array();foreach(array('principal','adicional') as $type){
 $sql=$type==='principal'?"SELECT c.id,c.login,c.simultaneo FROM sis_cliente c":"SELECT a.id,a.username login,a.simultaneo FROM sis_adicional a INNER JOIN sis_cliente c ON c.login=a.login";
 $sql.=" WHERE ($scope)".($login!==null?' AND c.login=?':'').' ORDER BY '.($type==='principal'?'c':'a').'.id'.($lock?' FOR UPDATE':'');
 $q=$db->prepare($sql);if($login!==null)$q->bind_param('s',$login);$q->execute();foreach($q->get_result()->fetch_all(MYSQLI_ASSOC) as $row){$row['type']=$type;$result[]=$row;}
 }return $result;
}
function mka_simultaneous_execute($db,$operator,$scope,$preview){
 $db->query("CREATE TABLE IF NOT EXISTS suite_simultaneous_operation_items(operation_id BIGINT UNSIGNED NOT NULL,record_type VARCHAR(12) NOT NULL,record_id INT NOT NULL,login VARCHAR(64) NOT NULL,previous_value VARCHAR(3) NULL,PRIMARY KEY(operation_id,record_type,record_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->begin_transaction();try{
 $q=$db->prepare('SELECT id,changed_count FROM suite_connection_operations WHERE operator=? AND request_key=? LIMIT 1');$q->bind_param('ss',$operator,$preview['key']);$q->execute();$old=$q->get_result()->fetch_assoc();if($old){$db->commit();return array('operation_id'=>(int)$old['id'],'changed'=>(int)$old['changed_count']);}
 $rows=mka_simultaneous_rows($db,$scope,$preview['login'],true);if(!hash_equals($preview['snapshot'],mka_connection_settings_snapshot($rows)))throw new UnexpectedValueException('Os cadastros mudaram desde a prévia. Gere uma nova prévia.');
 $value=$preview['options']['simultaneous'];if(!in_array($value,array('sim','nao'),true))throw new InvalidArgumentException('Valor inválido.');
 $changed=array_values(array_filter($rows,function($row)use($value){return $row['simultaneo']!==$value;}));$total=count($changed);$json=json_encode($preview['options']);$selection=$preview['login']===null?'all':'client';
 $q=$db->prepare('INSERT INTO suite_connection_operations(operator,request_key,selection,client_login,options_json,changed_count) VALUES(?,?,?,?,?,?)');$q->bind_param('sssssi',$operator,$preview['key'],$selection,$preview['login'],$json,$total);$q->execute();$operation=(int)$db->insert_id;
 $audit=$db->prepare('INSERT INTO suite_simultaneous_operation_items(operation_id,record_type,record_id,login,previous_value) VALUES(?,?,?,?,?)');
 foreach($changed as $row){$audit->bind_param('isiss',$operation,$row['type'],$row['id'],$row['login'],$row['simultaneo']);$audit->execute();$table=$row['type']==='principal'?'sis_cliente':'sis_adicional';$q=$db->prepare('UPDATE '.$table.' SET simultaneo=? WHERE id=?');$q->bind_param('si',$value,$row['id']);$q->execute();}
 $db->commit();return array('operation_id'=>$operation,'changed'=>$total);
 }catch(Throwable $e){$db->rollback();throw $e;}
}

// Counts describe persisted customer settings, not a provider-wide default.
function mka_connection_settings_current($db,$scope,$login=null){
 $sql="SELECT COUNT(*) total,COALESCE(SUM(c.autoip='sim'),0) autoip_yes,COALESCE(SUM(c.autoip='nao'),0) autoip_no,COALESCE(SUM(c.automac='sim'),0) automac_yes,COALESCE(SUM(c.automac='nao'),0) automac_no,COALESCE(SUM(TRIM(COALESCE(c.ip,''))<>''),0) saved_ip,COALESCE(SUM(TRIM(COALESCE(c.mac,''))<>''),0) saved_mac FROM sis_cliente c WHERE ($scope)".($login!==null?' AND c.login=?':'');
 $q=$db->prepare($sql);if($login!==null)$q->bind_param('s',$login);$q->execute();$result=$q->get_result()->fetch_assoc();foreach($result as $key=>$value)$result[$key]=(int)$value;
 if($login!==null){$rows=mka_connection_settings_rows($db,$scope,$login);if(count($rows)!==1)throw new DomainException('Cadastro indisponível.');$row=$rows[0];$result['client']=array('login'=>$row['login'],'ip'=>trim((string)$row['ip']),'mac'=>trim((string)$row['mac']),'autoip'=>$row['autoip'],'automac'=>$row['automac']);
  $result['client']['radius_mac']=array('state'=>'unknown','value'=>'');$result['client']['radius_ip']=array('state'=>'unknown','value'=>'');
  foreach(array('mac'=>"SELECT value FROM radcheck WHERE (username=? OR login=?) AND LOWER(attribute)='calling-station-id' AND ativo='s' LIMIT 1",'ip'=>"SELECT value FROM radreply WHERE (username=? OR login=?) AND LOWER(attribute)='framed-ip-address' LIMIT 1") as $kind=>$sql){try{$q=$db->prepare($sql);if(!$q)continue;$q->bind_param('ss',$row['login'],$row['login']);$q->execute();$rule=$q->get_result()->fetch_assoc();$result['client']['radius_'.$kind]=array('state'=>$rule&&trim((string)$rule['value'])!==''?'sim':'nao','value'=>$rule?trim((string)$rule['value']):'');}catch(Throwable $e){/* Keep unknown on unsupported native schemas. */}}
 }
 return $result;
}
