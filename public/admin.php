<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;
$form = [
    'title' => '',
    'body' => '',
    'publish_at' => '',
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
                INSERT INTO documents (title, body, created_by, publish_at)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$form['title'], $form['body'], $staff['id'], $publishAt]);
            $docId = (int) db()->lastInsertId();
            $readableId = assign_document_readable_id($docId, $form['title']);

            audit_log('document_created', 'document', $docId, ['title' => $form['title']]);
            audit_document_schedule_change($docId, null, $publishAt);

            header('Location: /admin.php?created=' . urlencode($readableId));
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document <?= h((string) $_GET['created']) ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
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
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Readable ID</th>
                    <th>Title</th>
                    <th>Availability</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php $adminIdentifier = document_admin_identifier($d); ?>
                    <tr>
                        <td class="id">
                            <?= h($adminIdentifier) ?>
                            <div class="table-subtext">Internal #<?= (int) $d['id'] ?></div>
                        </td>
                        <td><?= h($d['title']) ?></td>
                        <td>
                            <?php if (empty($d['publish_at'])): ?>
                                Available now
                            <?php elseif (is_document_published($d['publish_at'])): ?>
                                Published <?= h(format_publish_at_display($d['publish_at']) ?? $d['publish_at']) ?>
                            <?php else: ?>
                                Scheduled for <?= h(format_publish_at_display($d['publish_at']) ?? $d['publish_at']) ?>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td class="table-actions">
                            <a href="/document.php?doc=<?= urlencode($adminIdentifier) ?>" class="btn-link">Edit</a>
                            <a href="/share.php?doc=<?= urlencode($adminIdentifier) ?>" class="btn-link">Create share</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
