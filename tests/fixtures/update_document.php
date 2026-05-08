<?php

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['doc'] = $argv[1] ?? '0';
$_POST = [
    'title' => $argv[2] ?? 'Updated fixture document',
    'body' => $argv[3] ?? 'Updated fixture body',
    'publish_at' => $argv[4] ?? '',
];

require __DIR__ . '/../../public/document.php';
