<?php
declare(strict_types=1);
// Executed by phpMyAdmin on EVERY authenticated request. Never sent to the browser.
function get_login_credentials($unused): array {
    $panel=$_COOKIE['vps_session']??''; $lease=$_COOKIE['vpm_pma']??'';
    if(!preg_match('/^[a-f0-9]{64}$/D',$panel)||!preg_match('/^[a-f0-9]{64}$/D',$lease)) return ['',''];
    $key=trim((string)file_get_contents('/etc/vpsmanager-phpmyadmin/bridge.secret'));
    $c=curl_init('http://127.0.0.1:9084/credentials');
    curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,
        CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['X-PMA-Bridge: '.$key],
        CURLOPT_COOKIE=>'vps_session='.$panel.'; vpm_pma='.$lease]);
    $raw=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
    if($status!==200 || !$raw) return ['',''];
    $data=json_decode($raw,true);
    if(!is_array($data)||!preg_match('/^vpm_sso_[a-f0-9]{20}$/D',$data['username']??'')) return ['',''];
    return [$data['username'],$data['password']??''];
}
