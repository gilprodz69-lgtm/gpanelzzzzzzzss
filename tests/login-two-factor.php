<?php
use App\Services\{Auth,Totp};
use App\Helpers\Crypto;
$twoFactorFixture=function()use($db,$makeUser,$tenant,$masterId){
    unset($_COOKIE['vps_login_challenge'],$_COOKIE['vps_session'],$_SERVER['HTTP_AUTHORIZATION']);
    $db->query('DELETE FROM rate_limits');
    $id=$makeUser('mfa'.bin2hex(random_bytes(4)),'CLIENT',$tenant,$masterId);$secret=Totp::secret();
    $db->query('UPDATE users SET totp_secret=? WHERE id=?',[Crypto::encrypt($secret),$id]);
    $u=$db->one('SELECT * FROM users WHERE id=?',[$id]);$auth=new Auth($db);
    $issue=function()use($db,$u,$auth){
        $r=@$auth->login(['email'=>$u['email'],'password'=>'SecureTest!123456']);eq($r['requires_2fa'],true);
        // CLI has no cookie jar; replace the opaque value with a known fixture token.
        $row=$db->one('SELECT * FROM login_challenges WHERE user_id=? ORDER BY expires_at DESC LIMIT 1',[$u['id']]);
        $raw=bin2hex(random_bytes(32));$db->query('UPDATE login_challenges SET token_hash=? WHERE token_hash=?',[hash('sha256',$raw),$row['token_hash']]);
        $_COOKIE['vps_login_challenge']=$raw;return $raw;
    };
    return [$id,$secret,$auth,$issue,$u];
};
check('2FA starts only after correct password and creates no authenticated session',function()use($db,$twoFactorFixture){
    [$id,$secret,$auth,$issue,$u]=$twoFactorFixture();
    denied(fn()=>$auth->login(['email'=>$u['email'],'password'=>'wrong']),401);
    eq((int)$db->scalar('SELECT COUNT(*) FROM login_challenges WHERE user_id=?',[$id]),0);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $r=@$auth->login(['email'=>$u['email'],'password'=>'SecureTest!123456','code'=>Totp::code($secret,(int)floor(time()/30))]);
    eq($r['requires_2fa'],true);eq(isset($r['token']),false);
    eq((int)$db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id=?',[$id]),0);
    $issue();$r=@$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]);eq($r['message'],'Autenticado.');
    eq((int)$db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id=?',[$id]),1);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $issue();denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),401);
    eq((int)$db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id=?',[$id]),1);
});
check('2FA challenge expires, locks after five failures and cannot survive credential changes or suspension',function()use($db,$twoFactorFixture){
    [$id,$secret,$auth,$issue]=$twoFactorFixture();$issue();
    for($i=1;$i<=5;$i++)denied(fn()=>$auth->verifyTwoFactor(['code'=>'invalid']),$i===5?429:401);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),429);
    $issue();$db->query('UPDATE login_challenges SET expires_at=? WHERE user_id=?',[time()-1,$id]);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $issue();$db->query("UPDATE users SET status='suspended' WHERE id=?",[$id]);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $db->query("UPDATE users SET status='active' WHERE id=?",[$id]);$issue();
    $db->query('UPDATE users SET totp_secret=? WHERE id=?',[Crypto::encrypt(Totp::secret()),$id]);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $issue();$db->query('UPDATE users SET password_hash=? WHERE id=?',[password_hash('ChangedPassword!1234',PASSWORD_DEFAULT),$id]);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>'123456']),410);
    eq((int)$db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id=?',[$id]),0);
});
check('accounts without 2FA enter directly and disabling 2FA invalidates pending proof',function()use($db,$twoFactorFixture){
    [$id,$secret,$auth,$issue,$u]=$twoFactorFixture();$issue();
    $db->query('UPDATE users SET totp_secret=NULL WHERE id=?',[$id]);
    denied(fn()=>$auth->verifyTwoFactor(['code'=>Totp::code($secret,(int)floor(time()/30))]),410);
    $r=@$auth->login(['email'=>$u['email'],'password'=>'SecureTest!123456']);eq(isset($r['requires_2fa']),false);eq($r['message'],'Autenticado.');
    eq((int)$db->scalar('SELECT COUNT(*) FROM sessions WHERE user_id=?',[$id]),1);
});
unset($_COOKIE['vps_login_challenge']);
