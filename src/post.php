<?php

require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare("SELECT title, content, created_at FROM posts WHERE id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    echo '<h1>' . __('Not Found') . '</h1>';
    exit;
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($post['title']) ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
        <?php
        $header_edit_url = has_perm($account, PERM_POST_EDITOR) ? '/edit?edit=' . $id : '';
        require __DIR__ . '/header.php';
        ?>
        <div class="container">
            <h1 class="post-title"><?= htmlspecialchars($post['title']) ?></h1>
            <time><?= htmlspecialchars(fmt_date($post['created_at'])) ?></time>
            <div class="content"><?= clean_content($post['content']) ?></div>
        </div>
    </body>
</html>
