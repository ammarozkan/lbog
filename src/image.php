<?php

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_image']) || isset($_POST['delete_category']))) {
    header('Content-Type: application/json');

    if ($_POST) {
        verify_csrf();
    }

    if (isset($_POST['delete_category'])) {
        if (!$account || !has_perm($account, PERM_CATEGORY_REMOVER)) {
            http_response_code(403);
            echo json_encode(['error' => __('Not authorized.')]);
            exit;
        }

        $cat_id = (int)$_POST['delete_category'];
        if (!$cat_id) {
            http_response_code(400);
            echo json_encode(['error' => __('Invalid category.')]);
            exit;
        }

        $stmt = db()->prepare("SELECT COUNT(*) FROM images WHERE category_id = ?");
        $stmt->execute([$cat_id]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(['error' => __('Category is not empty.')]);
            exit;
        }

        $stmt = db()->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$cat_id]);

        log_action($account['id'], 'category_delete', "category #{$cat_id}");

        echo json_encode(['success' => true]);
        exit;
    }

    if (!$account || !has_perm($account, PERM_IMAGE_REMOVER)) {
        http_response_code(403);
        echo json_encode(['error' => __('Not authorized.')]);
        exit;
    }

    $file = $_POST['delete_image'] ?? '';
    if (!$file || !preg_match('/^[a-f0-9]+\.[a-z]+$/', $file)) {
        http_response_code(400);
        echo json_encode(['error' => __('Invalid image.')]);
        exit;
    }

    $stmt = db()->prepare("SELECT id, filename FROM images WHERE filename = ?");
    $stmt->execute([$file]);
    $img = $stmt->fetch();

    if (!$img) {
        http_response_code(404);
        echo json_encode(['error' => __('Image not found.')]);
        exit;
    }

    $path = __DIR__ . '/../data/uploads/' . $img['filename'];

    db()->prepare("DELETE FROM images WHERE id = ?")->execute([$img['id']]);

    if (is_file($path)) {
        unlink($path);
    }

    log_action($account['id'], 'image_delete', "image #{$img['id']} ({$img['filename']})");

    echo json_encode(['success' => true]);
    exit;
}

$file = $_GET['file'] ?? '';
if (!$file || !preg_match('/^[a-f0-9]+\.[a-z]+$/', $file)) {
    serve_placeholder();
}

$stmt = db()->prepare("SELECT id FROM images WHERE filename = ?");
$stmt->execute([$file]);
if (!$stmt->fetch()) {
    serve_placeholder();
}

$path = __DIR__ . '/../data/uploads/' . $file;
if (!is_file($path) || !is_readable($path)) {
    serve_placeholder();
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);

function serve_placeholder(): void
{
    $text = htmlspecialchars(__('Image not found'));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
        . '<defs>'
        . '<pattern id="checker" width="300" height="150" patternUnits="userSpaceOnUse">'
        . '<rect width="150" height="75" fill="#f0f0f0"/>'
        . '<rect x="150" width="150" height="75" fill="#e8e8e8"/>'
        . '<rect y="75" width="150" height="75" fill="#e8e8e8"/>'
        . '<rect x="150" y="75" width="150" height="75" fill="#f0f0f0"/>'
        . '<text x="75" y="46" text-anchor="middle" fill="#c8c8c8" font-size="14" font-family="sans-serif">' . $text . '</text>'
        . '<text x="225" y="46" text-anchor="middle" fill="#d4d4d4" font-size="14" font-family="sans-serif">' . $text . '</text>'
        . '<text x="75" y="121" text-anchor="middle" fill="#d4d4d4" font-size="14" font-family="sans-serif">' . $text . '</text>'
        . '<text x="225" y="121" text-anchor="middle" fill="#c8c8c8" font-size="14" font-family="sans-serif">' . $text . '</text>'
        . '</pattern>'
        . '</defs>'
        . '<rect width="100%" height="100%" fill="url(#checker)"/>'
        . '</svg>';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache');
    echo $svg;
    exit;
}
