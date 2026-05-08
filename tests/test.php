<?php

require __DIR__ . '/../lib/bootstrap.php';

$migrationDir = __DIR__ . '/../migrations';
$createdMigrationDir = false;

if (!is_dir($migrationDir)) {
    if (!mkdir($migrationDir, 0777, true) && !is_dir($migrationDir)) {
        fwrite(STDERR, "failed to create migrations directory\n");
        exit(1);
    }
    $createdMigrationDir = true;
}

$tempMigrations = [
    $migrationDir . '/zzz_test_01_create_marker_table.sql' => "
        CREATE TABLE migration_test_marker (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            note TEXT NOT NULL
        );
    ",
    $migrationDir . '/zzz_test_02_insert_marker_row.sql' => "
        INSERT INTO migration_test_marker (note)
        VALUES ('applied in order');
    ",
];

foreach ($tempMigrations as $path => $sql) {
    if (file_put_contents($path, $sql) === false) {
        fwrite(STDERR, "failed to write migration fixture\n");
        exit(1);
    }
}

register_shutdown_function(function () use ($tempMigrations, $migrationDir, $createdMigrationDir): void {
    foreach (array_keys($tempMigrations) as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    if ($createdMigrationDir && is_dir($migrationDir)) {
        @rmdir($migrationDir);
    }
});

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seed runs SQL migrations in filename order', function () {
    $stmt = db()->query('SELECT note FROM migration_test_marker LIMIT 1');
    $row = $stmt->fetch();

    assert_true($row !== false, 'expected seeded migration row');
    assert_true($row['note'] === 'applied in order', 'unexpected migration note: ' . var_export($row['note'], true));
});

test('admin document creation logs document_created with the new document ID', function () {
    $title = 'Route-level audit log test';
    $body = 'Route-level audit log test body.';
    $script = __DIR__ . '/fixtures/create_document.php';

    system(
        'php '
        . escapeshellarg($script)
        . ' '
        . escapeshellarg($title)
        . ' '
        . escapeshellarg($body)
        . ' > /dev/null',
        $rc
    );
    assert_true($rc === 0, 'document creation fixture failed');

    $docStmt = db()->prepare('
        SELECT id
        FROM documents
        WHERE title = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $docStmt->execute([$title]);
    $doc = $docStmt->fetch();

    assert_true($doc !== false, 'expected created document row');

    $auditStmt = db()->prepare('
        SELECT action, entity_type, entity_id
        FROM audit_log
        WHERE entity_type = ? AND entity_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $auditStmt->execute(['document', $doc['id']]);
    $audit = $auditStmt->fetch();

    assert_true($audit !== false, 'expected document audit log row');
    assert_true($audit['action'] === 'document_created', 'unexpected audit action: ' . var_export($audit['action'], true));
    assert_true((int) $audit['entity_id'] === (int) $doc['id'], 'expected audit row to reference the new document');
});

test('scheduled documents stay hidden until publish time and log schedule changes', function () {
    $title = 'Scheduled recipient visibility test';
    $body = 'This body should stay hidden before publish time.';
    $createScript = __DIR__ . '/fixtures/create_document.php';

    system(
        'php '
        . escapeshellarg($createScript)
        . ' '
        . escapeshellarg($title)
        . ' '
        . escapeshellarg($body)
        . ' > /dev/null',
        $rc
    );
    assert_true($rc === 0, 'document creation fixture failed');

    $docStmt = db()->prepare('
        SELECT id, publish_at
        FROM documents
        WHERE title = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $docStmt->execute([$title]);
    $doc = $docStmt->fetch();
    assert_true($doc !== false, 'expected scheduled document row');
    $docId = (int) $doc['id'];
    $docStmt = null;

    $publishInput = '2030-01-15T09:30';
    $expectedPublishAt = normalize_publish_at_input($publishInput);
    $updateScript = __DIR__ . '/fixtures/update_document.php';

    system(
        'php '
        . escapeshellarg($updateScript)
        . ' '
        . escapeshellarg((string) $docId)
        . ' '
        . escapeshellarg($title)
        . ' '
        . escapeshellarg($body)
        . ' '
        . escapeshellarg($publishInput)
        . ' > /dev/null',
        $rc
    );
    assert_true($rc === 0, 'document update fixture failed');

    $docStmt = db()->prepare('
        SELECT id, publish_at
        FROM documents
        WHERE title = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $docStmt->execute([$title]);
    $doc = $docStmt->fetch();
    assert_true($doc !== false, 'expected updated scheduled document row');
    assert_true($doc['publish_at'] === $expectedPublishAt, 'unexpected stored publish_at: ' . var_export($doc['publish_at'], true));
    $docStmt = null;

    $auditStmt = db()->prepare('
        SELECT action, details
        FROM audit_log
        WHERE entity_type = ? AND entity_id = ? AND action = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $auditStmt->execute(['document', $docId, 'document_schedule_changed']);
    $audit = $auditStmt->fetch();

    assert_true($audit !== false, 'expected document_schedule_changed audit row');

    $details = json_decode($audit['details'], true);
    assert_true(is_array($details), 'expected audit details to decode');
    assert_true(($details['previous_publish_at'] ?? null) === null, 'expected previous publish_at to be null');
    assert_true(($details['publish_at'] ?? null) === $expectedPublishAt, 'unexpected publish_at audit detail');
    $auditStmt = null;

    $token = random_token();
    $shareStmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email)
        VALUES (?, ?, ?)
    ');
    $shareStmt->execute([$docId, $token, 'scheduled@example.com']);
    $shareStmt = null;

    $viewScript = __DIR__ . '/fixtures/render_view.php';
    $output = [];
    exec(
        'php '
        . escapeshellarg($viewScript)
        . ' '
        . escapeshellarg($token),
        $output,
        $rc
    );
    assert_true($rc === 0, 'recipient view fixture failed');

    $html = implode("\n", $output);
    assert_true(str_contains($html, 'This document is not yet available'), 'expected not-yet-available message');
    assert_true(!str_contains($html, $body), 'expected scheduled document body to stay hidden');
});

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
