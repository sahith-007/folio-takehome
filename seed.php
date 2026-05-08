<?php

require __DIR__ . '/lib/bootstrap.php';

function run_sql_file(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Failed to read SQL file: ' . $path);
    }

    $pdo->exec($sql);
}

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
run_sql_file($pdo, __DIR__ . '/schema.sql');

// Seed-time migration runner for fresh local/test databases. We rebuild from
// schema.sql first, then replay checked-in SQL files in filename order without
// a versions table so `docker compose up` and the test suite can reseed cleanly.
$migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationFiles, SORT_STRING);

foreach ($migrationFiles as $migrationFile) {
    run_sql_file($pdo, $migrationFile);
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by)
    VALUES (?, ?, 1)
');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
]);
$docId = (int) $pdo->lastInsertId();
$readableId = assign_document_readable_id($docId, 'Welcome Packet');

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample doc:   http://localhost:8000/document.php?doc={$readableId}\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
