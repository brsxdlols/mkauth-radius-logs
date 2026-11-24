<?php
require_once __DIR__.'/access.php';
$radiusScope=radius_current_access_scope($conn);
if(!$radiusScope||!$radiusScope['full']){http_response_code(403);exit('Log geral requer acesso a todos os grupos de clientes.');}
