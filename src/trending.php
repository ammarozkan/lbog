<?php

require __DIR__ . '/db.php';

if ($_POST) {
    verify_csrf();
}

if (!$account || !has_perm($account, PERM_TRENDING_EDITOR)) {
    header('Location: /manage');
    exit;
}

$saved = false;

if (isset($_POST['clear'])) {
    db()->exec("DELETE FROM trending");
    log_action($account['id'], 'trending_clear', 'all trending cleared');
    header('Location: /trending');
    exit;
}

if (isset($_POST['save'])) {
    db()->exec("DELETE FROM trending");
    $ids = $_POST['trending'] ?? [];
    if (!empty($ids)) {
        $stmt = db()->prepare("INSERT INTO trending (post_id) VALUES (?)");
        foreach ($ids as $id) {
            $stmt->execute([(int)$id]);
        }
    }
    log_action($account['id'], 'trending_save', count($ids) . ' posts set as trending');
    header('Location: /');
    exit;
}

$posts = db()->query("SELECT id, title, created_at FROM posts ORDER BY created_at DESC")->fetchAll();
$trending_ids = db()->query("SELECT post_id FROM trending")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= __('Trending') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
        <div class="trending-page">
            <div class="trending-header">
                <h1><?= __('Trending') ?></h1>
                <a class="back-link" href="/"><?= __('Home') ?></a>
            </div>
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <div class="trending-toolbar">
                    <button class="publish" type="submit" name="save"><?= __('Okay') ?></button>
                    <button class="clear-btn" type="submit" name="clear"><?= __('Clear') ?></button>
                </div>
                <div class="trending-list">
                    <?php foreach ($posts as $post): ?>
                        <label class="trending-row">
                            <input type="checkbox" name="trending[]" value="<?= $post['id'] ?>"<?= in_array($post['id'], $trending_ids) ? ' checked' : '' ?>>
                            <span><?= htmlspecialchars($post['title']) ?></span>
                            <time><?= htmlspecialchars(fmt_date($post['created_at'])) ?></time>
                        </label>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
    </body>
</html>
