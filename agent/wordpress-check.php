$c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$db=new PDO('mysql:host=localhost;dbname='.$c['DB_NAME'].';charset=utf8mb4',$c['DB_USER'],$c['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo json_encode(['empty'=>!$db->query('SHOW TABLES')->fetchColumn()]);
