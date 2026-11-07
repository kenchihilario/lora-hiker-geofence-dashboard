<?php
require_once __DIR__.'/../src/operator_auth.php';require_local_operator();operator_session_start();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required');}
$provided=(string)($_POST['csrf_token']??'');$expected=operator_csrf_token();
if($provided===''||!hash_equals($expected,$provided)){http_response_code(403);exit('Valid CSRF token required');}
operator_logout();header('Location: /login.php',true,303);exit;
