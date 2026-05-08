<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$doc = find_document_by_admin_identifier($_GET['doc'] ?? '');

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">Document not found.</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$adminIdentifier = document_admin_identifier($doc);
$error = null;
$form = [
    'title' => $doc['title'],
    'body' => $doc['body'],
    'publish_at' => format_publish_at_input($doc['publish_at'] ?? null),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['title'] = trim($_POST['title'] ?? '');
    $form['body'] = trim($_POST['body'] ?? '');
    $form['publish_at'] = trim($_POST['publish_at'] ?? '');

    if ($form['title'] === '' || $form['body'] === '') {
        $error = 'Title and body are required.';
    } else {
        try {
            $publishAt = normalize_publish_at_input($form['publish_at']);

            $stmt = db()->prepare('
                UPDATE documents
                SET title = ?, body = ?, publish_at = ?
                WHERE id = ?
            ');
            $stmt->execute([$form['title'], $form['body'], $publishAt, $doc['id']]);

            audit_document_schedule_change((int) $doc['id'], $doc['publish_at'] ?? null, $publishAt);

            header('Location: /document.php?doc=' . urlencode($adminIdentifier) . '&updated=1');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

render_header('Edit · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Edit "<?= h($doc['title']) ?>"</h1>
<p class="page-subtitle">Readable ID <?= h($adminIdentifier) ?> · Update the document body and choose when recipients can view it.</p>

<?php if (!empty($_GET['updated'])): ?>
    <div class="banner banner-success">Document updated.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Document details</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= h($form['title']) ?>" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required><?= h($form['body']) ?></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at</label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="<?= h($form['publish_at']) ?>">
            <p class="form-hint">Leave blank to make the document available immediately.</p>
        </div>
        <button type="submit" class="btn">Save changes</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Recipient availability</h2>
    <?php if (empty($doc['publish_at'])): ?>
        <p class="card-copy">This document is available immediately to anyone with a share link.</p>
    <?php elseif (is_document_published($doc['publish_at'])): ?>
        <p class="card-copy">This document became visible to recipients on <?= h(format_publish_at_display($doc['publish_at']) ?? $doc['publish_at']) ?>.</p>
    <?php else: ?>
        <p class="card-copy">Recipients will see a not-yet-available message until <?= h(format_publish_at_display($doc['publish_at']) ?? $doc['publish_at']) ?>.</p>
    <?php endif ?>
</section>

<?php render_footer(); ?>
