<?php
// Caller must resolve the registered login within the administrator's groups first.
function radius_current_state($db,$login,$client) {
    $state=array('state'=>'unknown','login'=>$login,'name'=>$client['name'] ?? '',
        'client_url'=>$client['url'] ?? '', 'checked_at'=>date('d/m/Y H:i:s'));
    if(!$client)return $state;
    try {
        $s=$db->prepare("SELECT acctstarttime,acctstoptime FROM radacct WHERE acctstoptime IS NULL AND LOWER(TRIM(username))=LOWER(TRIM(?)) ORDER BY acctstarttime DESC LIMIT 1");
        if(!$s)return $state;
        $s->bind_param('s',$login);if(!$s->execute())return $state;
        $session=$s->get_result()->fetch_assoc();
        $state['state']=$session?'online':'offline';
        if(!$session){
            // Indexed username lookup, matching the existing Last connections report.
            $indexHint='';$indexes=$db->query("SHOW INDEX FROM radacct WHERE Column_name='username' AND Seq_in_index=1");
            if($indexes)while($index=$indexes->fetch_assoc())if(preg_match('/^[A-Za-z0-9_]+$/D',$index['Key_name'])){$indexHint=' FORCE INDEX (`'.$index['Key_name'].'`)';break;}
            // Never scan the full accounting history in a three-second monitor.
            if($indexHint!==''){
                $last=$db->prepare("SELECT acctstarttime,acctstoptime FROM radacct".$indexHint." WHERE username=? AND acctstoptime IS NOT NULL ORDER BY acctstoptime DESC LIMIT 1");
                if($last){$last->bind_param('s',$login);if($last->execute())$session=$last->get_result()->fetch_assoc();}
            }
        }
        $state['session']=$session ?: null;
    }catch(Throwable $e){ /* Never infer Offline from a failed status query. */ }
    return $state;
}

function radius_current_event($state,$rows,$history=null) {
    if(!in_array($state['state'] ?? '',array('online','offline'),true))return null;
    $online=$state['state']==='online';$session=$state['session'] ?? null;
    $time=$session ? ($online?$session['acctstarttime']:$session['acctstoptime']) : null;
    if($online && $time){
        $start=strtotime($time);$candidates=$rows;
        if($history && !empty($history['row']))$candidates[]=$history['row'];
        foreach($candidates as $row){
            $stamp=radius_log_timestamp($row['line']);
            if($row['type']==='conectados' && $stamp!==null && $start!==false && $stamp>=$start-30){
                $row['label']='Conexão atual';return $row;
            }
        }
    }
    $label=$online?'Conexão atual':'Última desconexão';
    $date=$time && strtotime($time)!==false ? date('d/m/Y H:i:s',strtotime($time)) : null;
    return array('type'=>$online?'conectados':'desconectados','label'=>$label,
        'login'=>$state['login'],'name'=>$state['name'],'client_url'=>$state['client_url'],
        'line'=>$date ? $date.' — '.($online?'Início da sessão atualmente aberta':'Encerramento da última sessão').' no MK-AUTH (radacct).' : 'Estado atual: '.($online?'Online':'Offline').'. Registro da '.($online?'conexão atual':'última desconexão').' indisponível no accounting.');
}

function radius_monitor_events($db,$state,$rows,$history=null) {
    if($history && !empty($history['rows']))$rows=array_merge($rows,$history['rows']);
    elseif($history && !empty($history['row']))$rows[]=$history['row'];
    $events=array();$authTimes=array();
    foreach($rows as $row){
        $time=radius_log_timestamp($row['line']);if($time===null)continue;
        if($row['type']==='conectados'){$row['label']='Login autorizado';$authTimes[]=$time;}
        $row['name']=$row['name'] ?? $state['name'];$row['client_url']=$row['client_url'] ?? $state['client_url'];$row['source']='radius';
        $row['event_time']=$time;$row['event_key']='log:'.sha1($row['line']);$events[$row['event_key']]=$row;
    }
    if(!empty($state['name']))try {
        $indexes=$db->query("SHOW INDEX FROM radacct WHERE Column_name='username' AND Seq_in_index=1");$hint='';
        if($indexes)while($index=$indexes->fetch_assoc())if(preg_match('/^[A-Za-z0-9_]+$/D',$index['Key_name'])){$hint=' FORCE INDEX (`'.$index['Key_name'].'`)';break;}
        if($hint!=='')foreach(array('acctstarttime','acctstoptime') as $column){
            $sql="SELECT acctstarttime,acctstoptime FROM radacct".$hint." WHERE username=? AND ".$column." IS NOT NULL ORDER BY ".$column." DESC LIMIT 5";
            $st=$db->prepare($sql);$st->bind_param('s',$state['login']);$st->execute();$result=$st->get_result();
            while($session=$result->fetch_assoc())foreach(array('acctstarttime','acctstoptime') as $field){
                $time=$session[$field]?strtotime($session[$field]):false;if($time===false)continue;
                $start=$field==='acctstarttime';$duplicate=false;
                if($start)foreach($authTimes as $authTime)if(abs($authTime-$time)<=30){$duplicate=true;break;}
                if($duplicate)continue;
                $key=($start?'start:':'stop:').$time;
                $events[$key]=array('type'=>$start?'conectados':'desconectados','label'=>$start?'Conexão':'Desconexão',
                    'login'=>$state['login'],'name'=>$state['name'],'client_url'=>$state['client_url'],'event_time'=>$time,'event_key'=>$key,'source'=>$start?'accounting_start':'accounting_stop',
                    'line'=>date('d/m/Y H:i:s',$time).' — '.($start?'Início':'Encerramento').' da sessão no MK-AUTH (radacct).');
            }
        }
    }catch(Throwable $e){ /* Keep genuine RADIUS events when accounting is unavailable. */ }
    $events=array_values($events);usort($events,function($a,$b){return $b['event_time']<=>$a['event_time'] ?: strcmp($a['event_key'],$b['event_key']);});
    return array_slice($events,0,5);
}
