<?php

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['token'] = $argv[1] ?? '';

require __DIR__ . '/../../public/view.php';
