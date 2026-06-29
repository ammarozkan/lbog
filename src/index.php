<?php

require __DIR__ . '/db.php';

$featured = db()
    ->query("SELECT id, title, content, created_at FROM posts ORDER BY created_at ASC LIMIT 1")
    ->fetch();

$trending_ids = db()->query("SELECT post_id FROM trending")->fetchAll(PDO::FETCH_COLUMN);

$all_posts = db()->query("SELECT id, title, created_at FROM posts ORDER BY created_at DESC")->fetchAll();

$trending_posts = [];
$posts = [];
foreach ($all_posts as $p) {
    if (in_array($p['id'], $trending_ids)) {
        $trending_posts[] = $p;
    } else {
        $posts[] = $p;
    }
}
$has_sidebar = count($trending_posts)
    || count($posts)
    || ($account && has_perm($account, PERM_TRENDING_EDITOR));
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= __('lbog') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
        <?php
        $header_edit_url = $account && has_perm($account, PERM_POST_CREATOR) ? '/edit' : '';
        $header_show_hamburger = $has_sidebar;
        require __DIR__ . '/header.php';
        ?>
        <?php if (!$featured && !count($posts)): ?>
            <p class="empty"><?= __('No posts yet.') ?></p>
        <?php else: ?>
            <div class="index-layout<?= $has_sidebar ? '' : ' no-sidebar' ?>">
                <?php if ($has_sidebar): ?>
                    <div class="index-sidebar">
                        <div class="sidebar-top">
                            <?php if ($account && has_perm($account, PERM_TRENDING_EDITOR)): ?>
                                <a class="settings-link" href="/trending">&#9881;</a>
                            <?php endif; ?>
                        </div>
                        <?php if (count($trending_posts)): ?>
                            <div class="trending-section">
                                <?php foreach ($trending_posts as $post): ?>
                                    <div class="post trending-item">
                                        <a href="/post?id=<?= $post['id'] ?>"><?= htmlspecialchars($post['title']) ?></a>
                                        <time><?= htmlspecialchars(fmt_date($post['created_at'])) ?></time>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="posts-section">
                            <?php foreach ($posts as $post): ?>
                                <div class="post">
                                    <a href="/post?id=<?= $post['id'] ?>"><?= htmlspecialchars($post['title']) ?></a>
                                    <time><?= htmlspecialchars(fmt_date($post['created_at'])) ?></time>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="index-featured">
                    <?php if ($featured): ?>
                        <div class="featured-post">
                            <h2><?= htmlspecialchars($featured['title']) ?></h2>
                            <time><?= htmlspecialchars(fmt_date($featured['created_at'])) ?></time>
                            <div class="content"><?= clean_content($featured['content']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <script>
            function toggleMenu() {
                var s = document.querySelector('.index-sidebar'),
                    b = document.querySelector('.menu-toggle');
                s.classList.toggle('open');
                b.innerHTML = s.classList.contains('open') ? '&#10005;' : '&#9776;';
            }
        </script>
    </body>
</html>
