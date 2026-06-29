<?php

require __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_POST) {
    verify_csrf();
}

if (!$account || !has_perm($account, PERM_IMAGE_UPLOADER)) {
    http_response_code(403);
    echo json_encode(['error' => __('Not authorized.')]);
    exit;
}

$category = trim($_POST['category'] ?? '');
$description = trim($_POST['description'] ?? '');
$file = $_FILES['image'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => __('Upload failed.')]);
    exit;
}

if ($category === '' || $description === '') {
    http_response_code(400);
    echo json_encode(['error' => __('Category and description are required.')]);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => __('Only JPEG, PNG, GIF, and WebP images are allowed.')]);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => __('Image must be under 10 MB.')]);
    exit;
}

$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
};

$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$upload_dir = __DIR__ . '/../data/uploads';
$dest = $upload_dir . '/' . $filename;

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0733, true);
}

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => __('Failed to save file.')]);
    exit;
}

$stmt = db()->prepare("SELECT id FROM categories WHERE name = ?");
$stmt->execute([$category]);
$cat_id = $stmt->fetchColumn();

if (!$cat_id) {
    $stmt = db()->prepare("INSERT INTO categories (name) VALUES (?) RETURNING id");
    $stmt->execute([$category]);
    $cat_id = $stmt->fetchColumn();
}

$stmt = db()->prepare("INSERT INTO images (filename, category_id, description, uploaded_by) VALUES (?, ?, ?, ?) RETURNING id");
$stmt->execute([$filename, $cat_id, $description, $account['id']]);
$img_id = $stmt->fetchColumn();

log_action($account['id'], 'image_upload', "image #{$img_id} ({$filename})");

echo json_encode([
    'url' => '/image?file=' . $filename,
    'id' => $img_id,
]);
