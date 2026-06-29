<?php

require __DIR__ . '/db.php';

if (PHP_SAPI === 'cli') {
    $cmd = $argv[1] ?? 'help';

    switch ($cmd) {
        case 'list':
            $rows = db()
                ->query("SELECT id, username, permissions, is_admin, created_at FROM accounts ORDER BY id")
                ->fetchAll();
            if (!$rows) {
                echo __('No accounts.') . "\n";
                break;
            }
            echo str_pad(__('ID'), 4) . str_pad(__('Username'), 22) . str_pad(__('Admin'), 7) . str_pad(__('Permissions'), 40) . __('Created') . "\n";
            echo str_repeat('-', 90) . "\n";
            foreach ($rows as $r) {
                echo str_pad($r['id'], 4) . str_pad($r['username'], 22) . str_pad($r['is_admin'] ? __('yes') : __('no'), 7) . str_pad(perms_to_labels($r['permissions']), 40) . fmt_date($r['created_at']) . "\n";
            }
            break;

        case 'create':
            $username = $argv[2] ?? null;
            if (!$username) {
                echo __('Usage: php src/manage.php create <username> [--admin]') . "\n";
                exit(1);
            }
            if (strlen($username) < 2) {
                echo __('Error: Username must be at least 2 characters.') . "\n";
                exit(1);
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                echo __('Error: Username can only contain letters, numbers, and underscores.') . "\n";
                exit(1);
            }

            echo __('Password: ');
            `stty -echo`;
            $password = trim(fgets(STDIN));
            `stty echo`;
            echo "\n";
            echo __('Repeat password: ');
            `stty -echo`;
            $password2 = trim(fgets(STDIN));
            `stty echo`;
            echo "\n";
            if ($password !== $password2) {
                echo __('Error: Passwords do not match.') . "\n";
                exit(1);
            }
            if (strlen($password) < 6) {
                echo __('Error: Password must be at least 6 characters.') . "\n";
                exit(1);
            }
            $stmt = db()->prepare("SELECT id FROM accounts WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                echo __('Error: Username \'%s\' already exists.', $username) . "\n";
                exit(1);
            }
            $is_admin = in_array('--admin', array_slice($argv, 3));
            $stmt = db()->prepare(
                "INSERT INTO accounts (username, password_hash, permissions, is_admin)"
                . " VALUES (?, ?, ?, ?) RETURNING id"
            );
            $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $is_admin ? PERM_ALL : 0, $is_admin]);
            echo __('Account \'%s\' created (id=%s).', $username, $stmt->fetchColumn()) . "\n";
            break;

        case 'delete':
            $id = (int)($argv[2] ?? 0);
            if (!$id) {
                echo __('Usage: php src/manage.php delete <id>') . "\n";
                exit(1);
            }
            $stmt = db()->prepare("SELECT username, is_admin FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $acc = $stmt->fetch();
            if (!$acc) {
                echo "Error: Account #{$id} not found.\n";
                exit(1);
            }
            if ($acc['is_admin']) {
                echo __('Warning: \'%s\' is an administrator. Type \'yes\' to confirm: ', $acc['username']);
                if (trim(fgets(STDIN)) !== 'yes') {
                    echo __('Aborted.') . "\n";
                    exit(1);
                }
            }
            if ($id === (int)db()->query("SELECT id FROM accounts WHERE username = '__deleted__'")->fetchColumn()) {
                echo __('Cannot delete the Deleted User placeholder.') . "\n";
                exit(1);
            }
            $deleted_id = db()->query("SELECT id FROM accounts WHERE username = '__deleted__'")->fetchColumn();
            if ($deleted_id) {
                db()->prepare("UPDATE posts SET author_id = ? WHERE author_id = ?")->execute([$deleted_id, $id]);
                db()->prepare("UPDATE images SET uploaded_by = ? WHERE uploaded_by = ?")->execute([$deleted_id, $id]);
            }
            db()->prepare("DELETE FROM accounts WHERE id = ?")->execute([$id]);
            echo __('Deleted account \'%s\' (id=%s).', $acc['username'], $id) . "\n";
            break;

        case 'make-admin':
            $id = (int)($argv[2] ?? 0);
            if (!$id) {
                echo __('Usage: php src/manage.php make-admin <id>') . "\n";
                exit(1);
            }
            $stmt = db()->prepare("SELECT username FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if (!$name) {
                echo __('Error: Account #%s not found.', $id) . "\n";
                exit(1);
            }
            db()
                ->prepare("UPDATE accounts SET is_admin = true, permissions = ? WHERE id = ?")
                ->execute([PERM_ALL, $id]);
            echo __('%s is now an administrator.', $name) . "\n";
            break;

        case 'perms':
            $id = (int)($argv[2] ?? 0);
            if (!$id) {
                echo __('Usage: php src/manage.php perms <id> [<perm> ...]') . "\n  " . __('Permissions: ') . implode(', ', $GLOBALS['PERM_LABELS']) . "\n";
                exit(1);
            }
            $stmt = db()->prepare("SELECT username, permissions, is_admin FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $acc = $stmt->fetch();
            if (!$acc) {
                echo __('Error: Account #%s not found.', $id) . "\n";
                exit(1);
            }
            if ($acc['is_admin']) {
                echo __('Error: Admins have all permissions. Demote first.') . "\n";
                exit(1);
            }
            $wanted = array_slice($argv, 3);
            if (!$wanted) {
                echo __('Permissions for %s: ', $acc['username']) . perms_to_labels($acc['permissions']) . "\n";
                break;
            }
            $bits = 0;
            foreach ($GLOBALS['PERM_LABELS'] as $i => $label) {
                if (in_array($label, $wanted)) {
                    $bits |= (1 << $i);
                }
            }
            db()->prepare("UPDATE accounts SET permissions = ? WHERE id = ?")->execute([$bits, $id]);
            echo __('Permissions for %s: ', $acc['username']) . perms_to_labels($bits) . "\n";
            break;

        case 'clean-attempts':
            $count = db()->exec("DELETE FROM login_attempts");
            echo __('Cleaned %s login attempt records.', $count) . "\n";
            break;

        case 'migrate':
            run_migration();
            echo __('Migration complete.') . "\n";
            break;

        case 'logs':
            $limit = min((int)($argv[2] ?? 50), 500);
            $rows = db()
                ->query(
                    "SELECT l.created_at, a.username, l.action, l.detail"
                    . " FROM logs l"
                    . " LEFT JOIN accounts a ON l.account_id = a.id"
                    . " ORDER BY l.created_at DESC LIMIT $limit"
                )
                ->fetchAll();
            if (!$rows) {
                echo __('No logs.') . "\n";
                break;
            }
            echo str_pad(__('Time'), 24) . str_pad(__('Account'), 20) . str_pad(__('Action'), 18) . __('Detail') . "\n";
            echo str_repeat('-', 100) . "\n";
            foreach ($rows as $r) {
                echo str_pad(fmt_date($r['created_at']), 24) . str_pad($r['username'] ?? __('deleted'), 20) . str_pad($r['action'], 18) . $r['detail'] . "\n";
            }
            break;

        default:
            echo __('lbog Manage CLI') . "\n\n";
            echo __('Usage: php src/manage.php <command> [args]') . "\n\n";
            echo __('Commands:') . "\n";
            echo __('  list                        List all accounts') . "\n";
            echo __('  create <user> [--admin]    Create a new account (password prompted)') . "\n";
            echo __('  delete <id>                 Delete a non-admin account') . "\n";
            echo __('  make-admin <id>            Grant administrator privileges') . "\n";
            echo __('  perms <id> [<perm> ...]     View or set permissions') . "\n";
            echo __('  logs [n]                    Show last n log entries (default 50)') . "\n";
            echo __('  clean-attempts              Clear all login attempt records') . "\n";
            echo __('  migrate                     Create or repair all database tables') . "\n";
            echo "\n" . __('Permissions: ') . implode(', ', $GLOBALS['PERM_LABELS']) . "\n";
    }
    exit;
}

try {
    db()->query("SELECT 1 FROM accounts LIMIT 1");
} catch (PDOException) {
    ?>
    <!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8">
            <title><?= __('Not Initialized') ?></title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <link rel="stylesheet" href="/style.css">
        </head>
        <body>
            <div class="manage-page">
                <h1><?= __('Database Not Initialized') ?></h1>
                <p><?= __('Run the following command to create all database tables:') ?></p>
                <pre>php src/manage.php migrate</pre>
                <p><?= __('Then refresh this page.') ?></p>
            </div>
        </body>
    </html>
    <?php
    exit;
}

$view_logs = ($_GET['view'] ?? null) === 'logs';
$message = '';
$error = '';

if ($_POST) {
    verify_csrf();
}

if (isset($_POST['login'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
    db()->prepare("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '15 minutes'")->execute();
    $stmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $attempts = (int)$stmt->fetchColumn();
    if ($attempts >= 5) {
        $error = __('Too many login attempts. Try again later.');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            $stmt = db()->prepare("SELECT * FROM accounts WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                db()->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
                session_regenerate_id(true);
                $_SESSION['account_id'] = $user['id'];
                log_action($user['id'], 'login', "account {$user['username']}");
                header('Location: /manage');
                exit;
            }
            db()->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)")->execute([$ip]);
            $error = __('Invalid username or password.');
        }
    }
}

if ($account && has_perm($account, PERM_ROLE_MANAGER) && isset($_POST['save_permissions'])) {
    $ids = $_POST['account_ids'] ?? [];
    $perms = $_POST['perms'] ?? [];
    foreach ($ids as $aid) {
        $aid = (int)$aid;
        if ($aid === $account['id']) {
            continue;
        }
        $stmt = db()->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmt->execute([$aid]);
        $t = $stmt->fetch();
        if (!$t || $t['is_admin']) {
            continue;
        }
        $bits = 0;
        foreach ($GLOBALS['PERM_LABELS'] as $i => $p) {
            if (in_array($p, $perms[$aid] ?? [])) {
                $bits |= (1 << $i);
            }
        }
        db()->prepare("UPDATE accounts SET permissions = ? WHERE id = ?")->execute([$bits, $aid]);
        log_action($account['id'], 'perm_change', "account {$t['username']} → {$bits}");
    }
    $message = __('Permissions saved.');
}

if ($account && isset($_POST['remove_account'])) {
    $aid = (int)$_POST['remove_account'];
    if ($aid !== $account['id'] && has_perm($account, PERM_ACCOUNT_REMOVER)) {
        $stmt = db()->prepare("SELECT username FROM accounts WHERE id = ? AND is_admin = false");
        $stmt->execute([$aid]);
        $name = $stmt->fetchColumn();
        if ($name) {
            $deleted_id = db()->query("SELECT id FROM accounts WHERE username = '__deleted__'")->fetchColumn();
            if ($deleted_id) {
                db()->prepare("UPDATE posts SET author_id = ? WHERE author_id = ?")->execute([$deleted_id, $aid]);
                db()->prepare("UPDATE images SET uploaded_by = ? WHERE uploaded_by = ?")->execute([$deleted_id, $aid]);
            }
            db()->prepare("DELETE FROM accounts WHERE id = ?")->execute([$aid]);
            log_action($account['id'], 'account_remove', "account {$name}");
            $message = __('Removed %s.', $name);
        }
    }
}

if ($account && isset($_POST['make_admin'])) {
    $aid = (int)$_POST['make_admin'];
    if ($account['is_admin'] && $aid !== $account['id']) {
        $stmt = db()->prepare("SELECT username FROM accounts WHERE id = ?");
        $stmt->execute([$aid]);
        $name = $stmt->fetchColumn();
        if ($name) {
            db()
                ->prepare("UPDATE accounts SET is_admin = true, permissions = ? WHERE id = ?")
                ->execute([PERM_ALL, $aid]);
            log_action($account['id'], 'make_admin', "account {$name}");
            $message = __('%s is now an administrator.', $name);
        }
    }
}

$reset_tables = ['login_attempts', 'trending', 'logs', 'images', 'categories', 'posts', 'accounts'];

if ($account && $account['is_admin'] && isset($_POST['reset_table'])) {
    $target = $_POST['reset_table'];
    if (!in_array($target, $reset_tables)) {
        $error = __('Invalid table.');
    } else {
        try {
            if ($target === 'accounts') {
                $deleted_id = db()->query("SELECT id FROM accounts WHERE username = '__deleted__'")->fetchColumn();
                if ($deleted_id) {
                    db()->prepare(
                        "UPDATE posts SET author_id = ?"
                        . " WHERE author_id NOT IN ("
                        . "     SELECT id FROM accounts WHERE is_admin = true OR username = '__deleted__'"
                        . " )"
                    )->execute([$deleted_id]);
                    db()->prepare(
                        "UPDATE images SET uploaded_by = ?"
                        . " WHERE uploaded_by NOT IN ("
                        . "     SELECT id FROM accounts WHERE is_admin = true OR username = '__deleted__'"
                        . " )"
                    )->execute([$deleted_id]);
                }
                db()->exec("DELETE FROM logs WHERE account_id NOT IN (SELECT id FROM accounts WHERE is_admin = true)");
                db()->exec("DELETE FROM accounts WHERE is_admin = false");
                log_action($account['id'], 'table_reset', 'accounts (kept admins, posts reassigned)');
            } elseif ($target === 'posts') {
                db()->exec("DELETE FROM logs WHERE action LIKE 'post\\_%'");
                db()->exec("DELETE FROM posts");
                log_action($account['id'], 'table_reset', $target);
            } else {
                db()->exec("DELETE FROM \"$target\"");
                log_action($account['id'], 'table_reset', $target);
            }
            $message = __('Table \'%s\' reset.', $target);
        } catch (PDOException $e) {
            $error = __('Cannot reset %s: dependent data exists. Clear child tables first.', $target);
        }
    }
}

$db_check_results = [];
if ($account && $account['is_admin'] && isset($_POST['db_check'])) {
    $pdo = db();
    $checks = [
        ['posts', [
            ['id', 'SERIAL PRIMARY KEY'],
            ['title', 'VARCHAR(255) NOT NULL'],
            ['content', 'TEXT NOT NULL'],
            ['created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
            ['author_id', 'INTEGER REFERENCES accounts(id)'],
        ]],
        ['accounts', [
            ['id', 'SERIAL PRIMARY KEY'],
            ['username', 'VARCHAR(100) UNIQUE NOT NULL'],
            ['password_hash', 'VARCHAR(255) NOT NULL'],
            ['permissions', 'INTEGER NOT NULL DEFAULT 0'],
            ['is_admin', 'BOOLEAN NOT NULL DEFAULT false'],
            ['created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
        ]],
        ['trending', [
            ['post_id', 'INTEGER PRIMARY KEY'],
        ]],
        ['logs', [
            ['id', 'SERIAL PRIMARY KEY'],
            ['account_id', 'INTEGER REFERENCES accounts(id) ON DELETE SET NULL'],
            ['action', 'VARCHAR(100) NOT NULL'],
            ['detail', 'TEXT NOT NULL'],
            ['created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
        ]],
        ['login_attempts', [
            ['ip_address', 'VARCHAR(45) NOT NULL'],
            ['attempted_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
        ]],
        ['categories', [
            ['id', 'SERIAL PRIMARY KEY'],
            ['name', 'VARCHAR(100) UNIQUE NOT NULL'],
        ]],
        ['images', [
            ['id', 'SERIAL PRIMARY KEY'],
            ['filename', 'VARCHAR(255) NOT NULL'],
            ['category_id', 'INTEGER NOT NULL REFERENCES categories(id)'],
            ['description', 'TEXT NOT NULL'],
            ['uploaded_by', 'INTEGER REFERENCES accounts(id)'],
            ['created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
        ]],
    ];

    foreach ($checks as [$table, $columns]) {
        $exists = $pdo
            ->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = '$table')")
            ->fetchColumn();
        if (!$exists) {
            $col_defs = implode(', ', array_map(fn($c) => "{$c[0]} {$c[1]}", $columns));
            $pdo->exec("CREATE TABLE $table ($col_defs)");
            $db_check_results[] = __('Created table `%s`.', $table);
            continue;
        }
        foreach ($columns as [$col, $def]) {
            $col_exists = $pdo
                ->query(
                    "SELECT EXISTS (SELECT FROM information_schema.columns"
                    . " WHERE table_name = '$table' AND column_name = '$col')"
                )
                ->fetchColumn();
            if (!$col_exists) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
                $db_check_results[] = __('Added column `%s`.`%s`.', $table, $col);
            }
        }
    }

    if (!$db_check_results) {
        $db_check_results[] = __('All tables and columns are correct.');
    }
    log_action($account['id'], 'db_check', count($db_check_results) . ' issues found');
}

if ($view_logs) {
    $logs = db()
        ->query(
            "SELECT l.*, a.username"
            . " FROM logs l"
            . " LEFT JOIN accounts a ON l.account_id = a.id"
            . " ORDER BY l.created_at DESC LIMIT 200"
        )
        ->fetchAll();
} else {
    $all_accounts = $account
        ? db()
            ->query("SELECT * FROM accounts ORDER BY is_admin DESC, created_at ASC")
            ->fetchAll()
        : [];
    $can_manage_roles = $account && has_perm($account, PERM_ROLE_MANAGER);
    $can_remove = $account && has_perm($account, PERM_ACCOUNT_REMOVER);
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= $view_logs ? __('Logs') : __('Manage') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
        <div class="manage-page">

            <?php if (!$account): ?>
                <h1><?= __('Manage') ?>
                    <span class="lang-links">
                        <a href="<?= lang_url('tr') ?>">TR</a>
                        <a href="<?= lang_url('en') ?>">EN</a>
                        <a href="<?= lang_url('fr') ?>">FR</a>
                    </span>
                </h1>
                <form method="post" class="manage-form">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <input type="text" name="username" placeholder="<?= __('Username') ?>" required autofocus>
                    <input type="password" name="password" placeholder="<?= __('Password') ?>" required>
                    <button class="publish" type="submit" name="login"><?= __('Log In') ?></button>
                </form>

            <?php elseif ($view_logs): ?>
                <div class="manage-header">
                    <h1><?= __('Logs') ?></h1>
                    <div class="manage-header-links">
                        <a class="back-link" href="/manage"><?= __('Back') ?></a>
                        <span class="lang-links">
                            <a href="<?= lang_url('tr') ?>">TR</a>
                            <a href="<?= lang_url('en') ?>">EN</a>
                            <a href="<?= lang_url('fr') ?>">FR</a>
                        </span>
                    </div>
                </div>
                <div class="logs-list">
                    <?php foreach ($logs as $l): ?>
                        <div class="log-row">
                            <time><?= htmlspecialchars(fmt_date($l['created_at'])) ?></time>
                            <span class="log-actor"><?= htmlspecialchars($l['username'] ?? __('deleted')) ?></span>
                            <span class="log-action"><?= htmlspecialchars($l['action']) ?></span>
                            <span class="log-detail"><?= htmlspecialchars($l['detail']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!count($logs)): ?>
                        <p class="empty"><?= __('No logs yet.') ?></p>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="manage-header">
                    <h1><?= __('Manage') ?></h1>
                    <div class="manage-header-links">
                        <a class="back-link" href="/"><?= __('Home') ?></a>
                        <a class="back-link" href="/manage?view=logs"><?= __('Logs') ?></a>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <button class="back-link" type="submit" name="logout"><?= __('Log Out') ?></button>
                        </form>
                        <span class="lang-links">
                            <a href="<?= lang_url('tr') ?>">TR</a>
                            <a href="<?= lang_url('en') ?>">EN</a>
                            <a href="<?= lang_url('fr') ?>">FR</a>
                        </span>
                    </div>
                </div>

                <div class="account-info">
                    <?= __('Logged in as ') ?>
                    <strong><?= htmlspecialchars($account['username']) ?></strong>
                    <?php if ($account['is_admin']): ?>
                        <span class="admin-badge"><?= __('administrator') ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($can_manage_roles || $can_remove): ?>
                    <form method="post" class="accounts-form">
                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                        <div class="accounts-list">
                            <?php foreach ($all_accounts as $a): ?>
                                <div class="account-row<?= $a['is_admin'] ? ' admin-glow' : '' ?>">
                                    <div class="account-name">
                                        <?= htmlspecialchars($a['username']) ?>
                                        <?php if ($a['id'] === $account['id']): ?>
                                            <span class="you-badge"><?= __('you') ?></span>
                                        <?php endif; ?>
                                        <?php if ($a['is_admin']): ?>
                                            <span class="admin-badge"><?= __('admin') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$a['is_admin'] || $a['id'] === $account['id']): ?>
                                        <div class="account-perms">
                                            <?php if ($can_manage_roles && !$a['is_admin']): ?>
                                                <?php foreach ($GLOBALS['PERM_LABELS'] as $i => $label): ?>
                                                    <label class="perm-check">
                                                        <input type="hidden" name="account_ids[]" value="<?= $a['id'] ?>">
                                                        <input type="checkbox" name="perms[<?= $a['id'] ?>][]" value="<?= $label ?>"<?= ($a['permissions'] & (1 << $i)) ? ' checked' : '' ?>>
                                                        <?= $label ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="account-actions">
                                        <?php if ($account['is_admin'] && !$a['is_admin']): ?>
                                            <button class="make-admin-btn" type="submit" name="make_admin" value="<?= $a['id'] ?>"><?= __('Make Admin') ?></button>
                                        <?php endif; ?>
                                        <?php if ($can_remove && !$a['is_admin']): ?>
                                            <button class="remove-btn" type="submit" name="remove_account" value="<?= $a['id'] ?>" onclick="return confirm('<?= __('Remove %s?', htmlspecialchars(addslashes($a['username']))) ?>')">&#128465;</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($can_manage_roles): ?>
                            <button class="publish" type="submit" name="save_permissions"><?= __('Save') ?></button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($account['is_admin']): ?>
                    <div class="reset-section">
                        <h2><?= __('Database Tools') ?></h2>
                        <form method="post" class="reset-forms">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <?php foreach ($reset_tables as $t): ?>
                                <button class="reset-btn" type="submit" name="reset_table" value="<?= $t ?>" onclick="return confirm('<?= __('Reset %s table? This cannot be undone.', $t) ?>')"><?= $t ?></button>
                            <?php endforeach; ?>
                            <button class="db-check-btn" type="submit" name="db_check"><?= __('Check & Repair') ?></button>
                        </form>
                        <?php if ($db_check_results): ?>
                            <div class="db-check-results">
                                <?php foreach ($db_check_results as $r): ?>
                                    <div class="db-check-row"><?= htmlspecialchars($r) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php if ($message): ?>
                <div class="msg success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="msg error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>
    </body>
</html>
