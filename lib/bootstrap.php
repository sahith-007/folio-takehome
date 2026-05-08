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

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
