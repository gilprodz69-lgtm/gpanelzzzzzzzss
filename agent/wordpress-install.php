$c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
ini_set('display_errors','0');
define('WP_INSTALLING',true);define('WP_ADMIN',true);
$_SERVER['HTTP_HOST']=$c['host'];$_SERVER['SERVER_NAME']=$c['host'];$_SERVER['HTTPS']='on';$_SERVER['SERVER_PORT']=443;$_SERVER['REQUEST_URI']='/wp-admin/install.php';$_SERVER['PHP_SELF']='/wp-admin/install.php';
function wp_mail($to,$subject,$message,$headers='',$attachments=[]){return false;}
ob_start();
require $c['path'].'/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
$result=wp_install($c['title'],$c['admin_user'],$c['admin_email'],true,'',$c['admin_password']);
if(is_wp_error($result)||!is_blog_installed()||empty($result['user_id']))exit(1);
update_option('permalink_structure','');
ob_end_clean();
echo json_encode(['installed'=>true,'version'=>$wp_version]);
