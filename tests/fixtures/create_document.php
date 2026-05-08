<?php

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'title' => $argv[1] ?? 'Fixture document',
    'body' => $argv[2] ?? 'Fixture body',
    'publish_at' => $argv[3] ?? '',
];

require __DIR__ . '/../../public/admin.php';
