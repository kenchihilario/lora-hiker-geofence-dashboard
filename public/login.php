<?php
require_once __DIR__.'/../src/operator_auth.php';require_local_operator();
if(operator_is_authenticated()){header('Location: /',true,302);exit;}
$error='';$credentials=operator_credentials();
if($_SERVER['REQUEST_METHOD']==='POST'){
    $csrf=(string)($_POST['csrf_token']??'');$expected=operator_csrf_token();
    if($csrf===''||!hash_equals($expected,$csrf)){$error='Your login form expired. Refresh and try again.';}
    elseif(operator_login(trim((string)($_POST['username']??'')),(string)($_POST['password']??''))){header('Location: /',true,303);exit;}
    else{$error='Incorrect username or password.';}
}
$token=operator_csrf_token();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Operator login · Trailwatch</title><link rel="stylesheet" href="assets/css/dashboard.css?v=20261106-1"></head><body><main class="login-shell"><section class="panel login-card"><div class="section-kicker">LOCAL OPERATOR ACCESS</div><h1>Trailwatch login</h1><p>Sign in from the base-camp computer to open the dashboard.</p><?php if(!$credentials): ?><p class="login-error">No operator account is set up. Run <code>php tools/set_operator_password.php</code> in the project folder first.</p><?php endif; ?><?php if($error!==''): ?><p class="login-error" role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?><form method="post" action="/login.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token,ENT_QUOTES,'UTF-8') ?>"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button type="submit" <?= !$credentials?'disabled':'' ?>>Sign in</button></form><p class="login-footnote">The server must stay bound to 127.0.0.1. Bridge requests continue to use the separate bridge token.</p></section></main></body></html>
