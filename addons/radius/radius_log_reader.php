<?php
function mka_radius_tail($path,$limit){
 $limit=max(1,min(2000,(int)$limit));$file=@fopen($path,'rb');if(!$file)throw new RuntimeException('Arquivo de log indisponível.');
 try{
  if(fseek($file,0,SEEK_END)!==0)throw new RuntimeException('Não foi possível posicionar a leitura do log.');
  $position=ftell($file);if($position===false)throw new RuntimeException('Não foi possível medir o log.');if($position===0)return array();
  $data='';$read=0;
  while($position>0&&substr_count($data,"\n")<=$limit&&$read<8388608){$size=min(65536,$position,8388608-$read);$position-=$size;if(fseek($file,$position)!==0)throw new RuntimeException('Falha ao ler o log.');$chunk=fread($file,$size);if($chunk===false)throw new RuntimeException('Falha ao ler o log.');$data=$chunk.$data;$read+=$size;}
  if($position>0){$newline=strpos($data,"\n");if($newline!==false)$data=substr($data,$newline+1);}
  if($data==='')return array();$lines=preg_split('/\r\n|\r|\n/',rtrim($data,"\r\n"));return array_slice($lines,-$limit);
 }finally{fclose($file);}
}
