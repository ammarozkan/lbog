<?php
// Expects: $header_edit_url (optional), $header_show_hamburger (optional)
?>
<div class="site-header">
    <h1><a href="/"><?= __('lbog') ?></a></h1>
    <div class="header-middle">
        <a href="/gallery"><?= __('Gallery') ?></a>
    </div>
    <div class="header-end">
        <?php if (isset($header_edit_url) && $header_edit_url): ?>
            <a class="header-btn" href="<?= htmlspecialchars($header_edit_url) ?>">&#9998;</a>
        <?php endif; ?>
        <?php if (!empty($header_show_pencil)): ?>
            <label class="dialog-label"><input type="checkbox" id="dialog-cb" checked> <?= __('dialog') ?></label>
            <button class="header-btn" id="gallery-edit-btn" title="<?= __('Edit') ?>">&#9998;</button>
        <?php endif; ?>
        <?php if ($account && $account['permissions'] > 0): ?>
            <a class="header-btn" href="/manage"><?= __('Manage') ?></a>
        <?php endif; ?>
        <?php if ($account): ?>
            <form method="post" class="header-form">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <button class="header-btn" type="submit" name="logout"><?= __('Log Out') ?></button>
            </form>
        <?php endif; ?>
        <?php if (!empty($header_show_hamburger)): ?>
            <button class="menu-toggle" onclick="toggleMenu()" aria-label="<?= __('Toggle menu') ?>">&#9776;</button>
        <?php endif; ?>
        <span class="lang-links">
            <a href="<?= lang_url('tr') ?>">TR</a>
            <a href="<?= lang_url('en') ?>">EN</a>
            <a href="<?= lang_url('fr') ?>">FR</a>
        </span>
    </div>
</div>
