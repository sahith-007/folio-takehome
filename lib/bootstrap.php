<?php

date_default_timezone_set('America/Chicago');

function app_timezone(): DateTimeZone {
    static $timezone = null;
    if ($timezone === null) {
        $timezone = new DateTimeZone(date_default_timezone_get());
    }
    return $timezone;
}

function utc_timezone(): DateTimeZone {
    static $timezone = null;
    if ($timezone === null) {
        $timezone = new DateTimeZone('UTC');
    }
    return $timezone;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function normalize_publish_at_input(?string $input): ?string {
    $input = trim((string) $input);
    if ($input === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $input, app_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
    ) {
        throw new InvalidArgumentException('Publish time must be a valid date and time.');
    }

    return $date->setTimezone(utc_timezone())->format('Y-m-d H:i:s');
}

function format_publish_at_input(?string $publishAt): string {
    if ($publishAt === null || $publishAt === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishAt, utc_timezone());
    if ($date === false) {
        return '';
    }

    return $date->setTimezone(app_timezone())->format('Y-m-d\TH:i');
}

function format_publish_at_display(?string $publishAt): ?string {
    if ($publishAt === null || $publishAt === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishAt, utc_timezone());
    if ($date === false) {
        return $publishAt;
    }

    return $date->setTimezone(app_timezone())->format('M j, Y g:i A T');
}

function is_document_published(?string $publishAt): bool {
    if ($publishAt === null || $publishAt === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $publishAt, utc_timezone());
    if ($date === false) {
        return true;
    }

    return $date <= new DateTimeImmutable('now', utc_timezone());
}

function audit_document_schedule_change(int $documentId, ?string $previousPublishAt, ?string $publishAt): void {
    if ($previousPublishAt === $publishAt) {
        return;
    }

    audit_log('document_schedule_changed', 'document', $documentId, [
        'previous_publish_at' => $previousPublishAt,
        'publish_at' => $publishAt,
    ]);
}

function document_admin_identifier(array $doc): string {
    $readableId = trim((string) ($doc['readable_id'] ?? ''));
    if ($readableId !== '') {
        return $readableId;
    }

    return (string) $doc['id'];
}

function slugify_document_title(string $title): string {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 24);
    $slug = rtrim($slug, '-');

    return $slug !== '' ? $slug : 'document';
}

function random_readable_suffix(int $length = 4): string {
    $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
    $maxIndex = strlen($alphabet) - 1;
    $suffix = '';

    for ($i = 0; $i < $length; $i++) {
        $suffix .= $alphabet[random_int(0, $maxIndex)];
    }

    return $suffix;
}

function readable_id_exists(string $readableId): bool {
    $stmt = db()->prepare('SELECT 1 FROM documents WHERE readable_id = ? LIMIT 1');
    $stmt->execute([$readableId]);
    return $stmt->fetchColumn() !== false;
}

function generate_document_readable_id(string $title): string {
    $base = slugify_document_title($title);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $base . '-' . random_readable_suffix();
        if (!readable_id_exists($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Could not generate a unique readable ID.');
}

function assign_document_readable_id(int $documentId, string $title): string {
    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE id = ?');
    $stmt->execute([$documentId]);
    $existingReadableId = $stmt->fetchColumn();

    if (is_string($existingReadableId) && $existingReadableId !== '') {
        return $existingReadableId;
    }

    $readableId = generate_document_readable_id($title);

    $stmt = db()->prepare('UPDATE documents SET readable_id = ? WHERE id = ?');
    $stmt->execute([$readableId, $documentId]);

    return $readableId;
}

function find_document_by_admin_identifier($identifier): ?array {
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
        return null;
    }

    if (ctype_digit($identifier)) {
        $stmt = db()->prepare('
            SELECT *
            FROM documents
            WHERE id = ? OR readable_id = ?
            LIMIT 1
        ');
        $stmt->execute([(int) $identifier, $identifier]);
    } else {
        $stmt = db()->prepare('
            SELECT *
            FROM documents
            WHERE readable_id = ?
            LIMIT 1
        ');
        $stmt->execute([$identifier]);
    }

    $doc = $stmt->fetch();
    return $doc !== false ? $doc : null;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
