<?php

require_once __DIR__.'/init_coverage.php';

/**
 * The login form is usually managed by Cosign. This is a fake login form that
 * can be used during development, and presents the same interface to the app.
 *
 * 1. configure the web server to use app_logindev.php instead of app.php
 * 2. add ALLOW_APP_LOGINDEV=1 to the environment (with SetEnv or equivalent)
 * 3. stop doing "SetEnv REMOTE_USER some_specific_username"
 */

if (php_sapi_name() == 'cli-server') {
  // from vendor/symfony/symfony/src/Symfony/Bundle/FrameworkBundle/Resources/config/router.php
  if (is_file($_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_NAME'])) {
    return false;
  }
  $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'app_dev.php';
  $_SERVER['ALLOW_APP_LOGINDEV'] = 1;
}

if (!isset($_SERVER['ALLOW_APP_LOGINDEV'])) die('app_logindev not allowed');

require_once __DIR__.'/../app/bootstrap.php.cache';
use Symfony\Component\HttpFoundation\Request;
$path = Request::createFromGlobals()->getPathInfo();

if ($path == '/login' && $_SERVER['REQUEST_METHOD'] == 'POST') {
  setcookie('username', $_POST['username'], 0, '/');
  header('Location: '.$_SERVER['REQUEST_URI']);
  exit();
}

unset($_SERVER['REMOTE_USER']);
if (isset($_COOKIE['username'])) $_SERVER['REMOTE_USER'] = $_COOKIE['username'];

if ($path == '/login' && !isset($_SERVER['REMOTE_USER'])) {
  ?>
  <!DOCTYPE html>
  <meta charset="UTF-8">
  <title>Anketa dev login</title>
  <form method="POST" style="position: absolute; left: 0; right: 0; top: 40%; text-align: center;">
  Username: <input type="text" name="username" id="username"> <input type="submit" value="Login">
  </form>
  <script>document.getElementById('username').focus();</script>
  <?php
  exit();
}

if ($path == '/logout') {
  setcookie('username', '', time()-3600, '/');
}

require 'app_dev.php';
