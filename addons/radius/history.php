<?php
// Bounded, on-demand history lookup. Never called by the periodic refresh.
function radius_log_timestamp($line) {
    if (!preg_match('/^\s*((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s*:/', $line, $m)) return null;
    $value=strtotime($m[1]);return $value===false ? null : $value;
}
function radius_log_range($lines, $files=1, $complete=true) {
    $dates=array();foreach($lines as $line){$t=radius_log_timestamp($line);if($t!==null)$dates[]=$t;}
    return array('from'=>$dates?date('d/m/Y H:i:s',min($dates)):null,'to'=>$dates?date('d/m/Y H:i:s',max($dates)):null,'files'=>$files,'complete'=>$complete);
}
function radius_history_result($row,$note,$dates,$files,$complete=true) {
    return array('row'=>$row,'note'=>$note,'range'=>array('from'=>$dates?date('d/m/Y H:i:s',min($dates)):null,'to'=>$dates?date('d/m/Y H:i:s',max($dates)):null,'files'=>$files,'complete'=>$complete));
}
function radius_latest_history($login,$limit=1) {
    $limit=max(1,min(5,(int)$limit));$collected=array();
    $base=radius_log_path(); $files=array($base);
    for($i=1;$i<=14;$i++) foreach(array($base.'.'.$i,$base.'.'.$i.'.gz') as $p) if(is_readable($p)) $files[]=$p;
    $start=microtime(true);$bytes=0;$searched=0;$dates=array();
    foreach($files as $file) {
        $gzip=substr($file,-3)==='.gz';
        $h=$gzip?@gzopen($file,'rb'):@fopen($file,'rb');if(!$h)continue;
        $last=null;$matches=array();$complete=true;
        while(!($gzip?gzeof($h):feof($h))) {
            if($bytes>=67108864 || microtime(true)-$start>2){$complete=false;break;}
            $line=$gzip?gzgets($h,65536):fgets($h,65536);if($line===false)break;
            $bytes+=strlen($line);
            $stamp=radius_log_timestamp($line);if($stamp!==null){if(!$dates)$dates=array($stamp,$stamp);else $dates=array(min($dates[0],$stamp),max($dates[1],$stamp));}
            if(strcasecmp(radius_extract_login($line),$login)===0){$matches[]=trim($line);if(count($matches)>$limit)array_shift($matches);}
        }
        $gzip?gzclose($h):fclose($h);$searched++;
        foreach(array_reverse($matches) as $last){
            $last=preg_replace('/\[([^\/\]]+)\/[^\]]*\]/','[$1/***]',$last);
            $last=preg_replace('/((?:User-Password|Cleartext-Password|CHAP-Password)\s*[:=]\s*)("[^"]*"|\S+)/i','$1***',$last);
            $type=radius_log_type($last);$labels=radius_type_labels();
            $collected[]=array('type'=>$type,'label'=>$labels[$type],'line'=>$last,'login'=>$login);
            if(count($collected)>=$limit)break;
        }
        if(!$complete || count($collected)>=$limit){
            $result=radius_history_result($collected[0] ?? null,$complete?'Últimos registros localizados no histórico disponível.':'Busca histórica parcial; limite seguro atingido.',$dates,$searched,$complete);
            $result['rows']=$collected;return $result;
        }
    }
    if($collected){$result=radius_history_result($collected[0],'Registros localizados no histórico disponível.',$dates,$searched);$result['rows']=$collected;return $result;}
    return radius_history_result(null,'Nenhum registro deste login nos '.$searched.' arquivos consultados. O histórico pode ter sido removido pela retenção.',$dates,$searched);
}
