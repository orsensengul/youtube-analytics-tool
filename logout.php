<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';

Database::init($config['database']);
Auth::startSession($config['session']);

Auth::logout();

header('Location: login.php');
exit;
