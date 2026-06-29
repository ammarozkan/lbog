<?php

define('PERM_POST_CREATOR', 1);
define('PERM_POST_EDITOR', 2);
define('PERM_TRENDING_EDITOR', 4);
define('PERM_ACCOUNT_REMOVER', 8);
define('PERM_ROLE_MANAGER', 16);
define('PERM_IMAGE_UPLOADER', 32);
define('PERM_IMAGE_REMOVER', 64);
define('PERM_CATEGORY_REMOVER', 128);
define('PERM_ALL', 255);

$PERM_LABELS = ['post_creator', 'post_editor', 'trending_editor', 'account_remover', 'role_manager', 'image_uploader', 'image_remover'];

function perms_to_labels(int $bits): string
{
    global $PERM_LABELS;
    $labels = [];
    foreach ($PERM_LABELS as $i => $label) {
        if ($bits & (1 << $i)) {
            $labels[] = $label;
        }
    }
    return $labels ? implode(', ', $labels) : __('none');
}

function fmt_date(string $ts): string
{
    $dt = new DateTimeImmutable($ts);
    return __($dt->format('F')) . $dt->format(' j, Y H:i');
}

function load_language(): array
{
    $lang_dir = __DIR__ . '/../lang';
    $langs = ['en', 'tr', 'fr'];
    $prefer = $_GET['lang'] ?? $_COOKIE['lang'] ?? '';

    if (!$prefer && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $first = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0];
        $prefer = substr($first, 0, 2);
    }

    $code = in_array($prefer, $langs) ? $prefer : 'en';
    $path = "$lang_dir/$code.php";

    if ($code !== 'en' && !is_file($path)) {
        $code = 'en';
        $path = "$lang_dir/en.php";
    }

    return is_file($path) ? (require $path) : [];
}

function __(string $key, mixed ...$args): string
{
    if (!isset($GLOBALS['_translations'])) {
        $GLOBALS['_translations'] = load_language();
    }
    $text = $GLOBALS['_translations'][$key] ?? $key;
    return $args ? sprintf($text, ...$args) : $text;
}

function lang_url(string $lang): string
{
    $params = $_GET;
    $params['lang'] = $lang;
    return '?' . http_build_query($params);
}

if (PHP_SAPI !== 'cli') {
    $sess_path = getenv('SESSION_PATH') ?: __DIR__ . '/../data/sessions';
    if (!is_dir($sess_path)) {
        mkdir($sess_path, 0733, true);
    }
    ini_set('session.save_path', $sess_path);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();

    header('X-Frame-Options: DENY');

    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'tr', 'fr'])) {
        setcookie('lang', $_GET['lang'], time() + 365 * 86400, '/');
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'pgsql:host=/var/run/postgresql;dbname=' . (getenv('DB_NAME') ?: 'lbog'),
            'postgres',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    return $pdo;
}

function run_migration(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id SERIAL PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        permissions INTEGER NOT NULL DEFAULT 0,
        is_admin BOOLEAN NOT NULL DEFAULT false,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id SERIAL PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE posts ADD COLUMN IF NOT EXISTS author_id INTEGER REFERENCES accounts(id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS trending (
        post_id INTEGER PRIMARY KEY REFERENCES posts(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id SERIAL PRIMARY KEY,
        account_id INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
        action VARCHAR(100) NOT NULL,
        detail TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        ip_address VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id SERIAL PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        category_id INTEGER NOT NULL REFERENCES categories(id),
        description TEXT NOT NULL,
        uploaded_by INTEGER REFERENCES accounts(id),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("INSERT INTO accounts (username, password_hash, permissions, is_admin)
        SELECT '__deleted__', '!disabled', 0, false
        WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE username = '__deleted__')");
}

function has_perm(?array $account, int $perm): bool
{
    return $account && ($account['is_admin'] || ($account['permissions'] & $perm));
}

function get_account(): ?array
{
    $id = $_SESSION['account_id'] ?? null;
    if (!$id) {
        return null;
    }
    try {
        $stmt = db()->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException) {
        return null;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (($_POST['_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(419);
        echo __('CSRF validation failed.');
        exit;
    }
}

function log_action(?int $account_id, string $action, string $detail): void
{
    $stmt = db()->prepare("INSERT INTO logs (account_id, action, detail) VALUES (?, ?, ?)");
    $stmt->execute([$account_id, $action, $detail]);
}

if (PHP_SAPI !== 'cli') {
    $account = get_account();

    if (isset($_POST['logout'])) {
        verify_csrf();
        $aid = $_SESSION['account_id'] ?? null;
        $_SESSION = [];
        setcookie(session_name(), '', ['expires' => 1, 'path' => '/']);
        session_destroy();
        if ($aid) {
            try {
                $stmt = db()->prepare("SELECT username FROM accounts WHERE id = ?");
                $stmt->execute([$aid]);
                $u = $stmt->fetchColumn();
                log_action($aid, 'logout', $u ? "account {$u}" : "account #{$aid}");
            } catch (PDOException) {
            }
        }
        header('Location: /');
        exit;
    }
} else {
    $account = null;
}

function clean_content(string $html): string
{
    // XSS sanitization
    $html = preg_replace('/<script[^>]*>[\s\S]*?<\/script>/i', '', $html);
    $html = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $html);
    $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace(
        '/(href|src|action|formaction)\s*=\s*(?:"\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|"\s*vbscript:[^"]*"|\'\s*vbscript:[^\']*\'|javascript:[^\s>]+)/i',
        '$1="#"',
        $html
    );
    $allowed = '<p><br><strong><b><em><i><u><s><h1><h2><h3><h4><h5><h6>'
        . '<ul><ol><li><blockquote><pre><code><a><img><span><div><hr>'
        . '<sub><sup><table><thead><tbody><tr><th><td><abbr><address>'
        . '<dd><del><dfn><dl><dt><ins><kbd><q><samp><small><strike><var>';
    $html = strip_tags($html, $allowed);

    // Editor-internal cleanup
    $html = preg_replace('/<span[^>]*class="img-handle"[^>]*>[\s\S]*?<\/span>/', '', $html);
    $html = preg_replace('/<span[^>]*class="img-del-btn"[^>]*>[\s\S]*?<\/span>/', '', $html);
    $html = preg_replace('/<span[^>]*class="img-resize-handle"[^>]*>[\s\S]*?<\/span>/', '', $html);
    $html = preg_replace('/\b(draggable)="[^"]*"/', '', $html);
    $html = preg_replace('/\bsrc="\/uploads\/([^"]+)"/', 'src="/image?file=$1"', $html);
    return $html;
}
