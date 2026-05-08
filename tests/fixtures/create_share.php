<?php

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_GET['doc'] = $argv[1] ?? '';
$_POST = [
    'email' => $argv[2] ?? 'fixture@example.com',
];

require __DIR__ . '/../../public/share.php';
