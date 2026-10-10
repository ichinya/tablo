<?php
declare(strict_types=1);
$frame=stream_get_contents(STDIN,2049); $lengths=array_values(unpack('N3',substr($frame,0,12)));
$payload=json_decode(substr($frame,12+$lengths[0]+$lengths[1],$lengths[2]),true,8,JSON_THROW_ON_ERROR);
$socket=stream_socket_server('tcp://127.0.0.1:'.$payload['port'],$errno,$message);
if($socket===false){exit(2);}
file_put_contents($payload['marker_path'],'owned-listener-ready');
sleep(30);
