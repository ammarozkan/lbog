<?php

require __DIR__ . '/db.php';

$categories = db()
    ->query(
        "SELECT c.id, c.name, COUNT(i.id) AS cnt"
        . " FROM categories c"
        . " LEFT JOIN images i ON i.category_id = c.id"
        . " GROUP BY c.id, c.name ORDER BY c.name"
    )
    ->fetchAll(PDO::FETCH_ASSOC);

$images_by_cat = [];
$all_images = [];
foreach ($categories as $cat) {
    $stmt = db()->prepare(
        "SELECT i.id, i.filename, i.description, i.created_at, a.username, i.category_id"
        . " FROM images i"
        . " LEFT JOIN accounts a ON i.uploaded_by = a.id"
        . " WHERE i.category_id = ?"
        . " ORDER BY i.created_at DESC"
    );
    $stmt->execute([$cat['id']]);
    $imgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $images_by_cat[$cat['id']] = [
        'name' => $cat['name'],
        'images' => $imgs,
    ];
    foreach ($imgs as $img) {
        $img['category'] = $cat['name'];
        $all_images[] = $img;
    }
}
$images_json = json_encode($all_images);
if ($images_json === false) {
    $images_json = '[]';
}
$can_delete_images = $account && has_perm($account, PERM_IMAGE_REMOVER);
$can_delete_cat = $account && has_perm($account, PERM_CATEGORY_REMOVER);
$show_toolbar = $account && ($account['is_admin'] || has_perm($account, PERM_POST_CREATOR));
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= __('Gallery') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="/style.css">
    </head>
    <body>
        <?php
        $header_edit_url = '';
        $header_show_pencil = $show_toolbar;
        require __DIR__ . '/header.php';
        ?>
        <div class="gallery-page">
            <h1><?= __('Gallery') ?></h1>
            <div id="gallery-content">
                <?php if (!$categories): ?>
                    <p class="empty" id="empty-gallery-msg"><?= __('No images yet.') ?></p>
                <?php else: ?>
                    <?php foreach ($images_by_cat as $cat_id => $cat_data): ?>
                        <div class="gallery-category" data-cat-id="<?= $cat_id ?>">
                            <h2<?php if ($can_delete_cat && !count($cat_data['images'])): ?> class="cat-removable"<?php endif; ?>><?= htmlspecialchars($cat_data['name']) ?></h2>
                            <div class="gallery-grid">
                                <?php foreach ($cat_data['images'] as $img): ?>
                                    <div class="gallery-item">
                                        <img src="/image?file=<?= htmlspecialchars($img['filename']) ?>" alt="<?= htmlspecialchars($img['description']) ?>" loading="lazy">
                                        <p class="gallery-desc"><?= htmlspecialchars($img['description']) ?></p>
                                        <p class="gallery-meta"><?= __('by %s', htmlspecialchars($img['username'] ?? __('deleted'))) ?> &middot; <?= htmlspecialchars(fmt_date($img['created_at'])) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div id="lightbox" class="lightbox" style="display:none" onclick="if(event.target===this)closeLightbox()">
            <button class="lightbox-prev" onclick="prevImage()">
                <svg viewBox="0 0 24 24" width="40" height="40"><path d="M15 4l-8 8 8 8" stroke="#fff" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="lightbox-content" onclick="event.stopPropagation()">
                <div class="lightbox-image-wrap">
                    <img id="lightbox-img" src="" alt="">
                </div>
                <div class="lightbox-info">
                    <p class="lightbox-desc" id="lightbox-desc"></p>
                    <p class="lightbox-meta" id="lightbox-meta"></p>
                </div>
            </div>
            <button class="lightbox-next" onclick="nextImage()">
                <svg viewBox="0 0 24 24" width="40" height="40"><path d="M9 4l8 8-8 8" stroke="#fff" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php if ($can_delete_images): ?>
                <button class="lightbox-delete-btn" data-confirm="<?= __('Delete this image?') ?>" title="<?= __('Delete image') ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22">
                        <path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m-9 0v13a1 1 0 001 1h6a1 1 0 001-1V6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="10" y1="11" x2="10" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="14" y1="11" x2="14" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <script>
            var images = <?= $images_json ?>;
            var lbIdx = -1;
            var editMode = false;
            var _token = '<?= csrf_token() ?>';
            var _t = <?= json_encode([
                'by' => __('by %s'),
                'deleted' => __('deleted'),
                'Uncategorized' => __('Uncategorized'),
                'dialog' => __('dialog'),
                'Delete this category?' => __('Delete this category?'),
                'Delete failed.' => __('Delete failed.'),
                'mon' => [
                    __('January'), __('February'), __('March'),
                    __('April'), __('May'), __('June'),
                    __('July'), __('August'), __('September'),
                    __('October'), __('November'), __('December')
                ],
            ]) ?>;

            function fmtDate(ts) {
                if (!ts) {
                    return '';
                }
                var p = ts.split(/[-\s:.]/);
                return _t.mon[parseInt(p[1]) - 1] + ' ' + parseInt(p[2]) + ', ' + p[0] + ' ' + (p[3] || '00') + ':' + (p[4] || '00');
            }

            function openLightbox(idx) {
                lbIdx = idx;
                var img = images[idx];
                if (!img) {
                    return;
                }
                document.getElementById('lightbox').style.display = 'grid';
                document.getElementById('lightbox-img').src = '/image?file=' + img.filename;
                document.getElementById('lightbox-desc').textContent = img.description;
                document.getElementById('lightbox-meta').textContent = (img.category || _t.Uncategorized) + ' \u00b7 ' + _t.by.replace('%s', img.username || _t.deleted) + ' \u00b7 ' + fmtDate(img.created_at);
            }

            function closeLightbox() {
                document.getElementById('lightbox').style.display = 'none';
                lbIdx = -1;
            }

            function prevImage() {
                if (lbIdx > 0) {
                    openLightbox(lbIdx - 1);
                }
            }

            function nextImage() {
                if (lbIdx < images.length - 1) {
                    openLightbox(lbIdx + 1);
                }
            }

            document.addEventListener('keydown', function (e) {
                if (lbIdx < 0) {
                    return;
                }
                if (e.key === 'Escape') {
                    closeLightbox();
                }
                if (e.key === 'ArrowLeft') {
                    prevImage();
                }
                if (e.key === 'ArrowRight') {
                    nextImage();
                }
            });

            function showEmptyState() {
                var cont = document.getElementById('gallery-content');
                cont.innerHTML = '<p class="empty">' + '<?= __('No images yet.') ?>' + '</p>';
            }

            function deleteImage(idx, onDone) {
                var img = images[idx];
                if (!img) {
                    return;
                }
                var fd = new FormData();
                fd.append('_token', _token);
                fd.append('delete_image', img.filename);
                fetch('/image', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        var items = document.querySelectorAll('.gallery-item');
                        if (items[idx]) {
                            items[idx].remove();
                        }
                        images.splice(idx, 1);
                        if (images.length === 0) {
                            showEmptyState();
                            rebindGalleryItems();
                            if (onDone) onDone();
                            return;
                        }
                        rebindGalleryItems();
                        if (onDone) onDone();
                    })
                    .catch(function () {
                        alert(_t['Delete failed.']);
                    });
            }

            function deleteCategory(el) {
                if (!confirm(_t['Delete this category?'])) {
                    return;
                }
                var catDiv = el.closest('.gallery-category');
                if (!catDiv) {
                    return;
                }
                var catId = catDiv.getAttribute('data-cat-id');
                var fd = new FormData();
                fd.append('_token', _token);
                fd.append('delete_category', catId);
                fetch('/image', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) {
                            alert(data.error);
                            return;
                        }
                        images = images.filter(function (img) { return String(img.category_id) !== catId; });
                        catDiv.remove();
                        if (images.length === 0) {
                            showEmptyState();
                        }
                        rebindGalleryItems();
                    })
                    .catch(function () {
                        alert(_t['Delete failed.']);
                    });
            }

            function rebindGalleryItems() {
                var items = document.querySelectorAll('.gallery-item');
                [].slice.call(items).forEach(function (item, idx) {
                    item.onclick = function () {
                        if (editMode) {
                            var cb = document.getElementById('dialog-cb');
                            if (!cb || cb.checked) {
                                if (!confirm('<?= __('Delete this image?') ?>')) {
                                    return;
                                }
                            }
                            deleteImage(idx);
                        } else {
                            openLightbox(idx);
                        }
                    };
                });
            }
            rebindGalleryItems();

            var editBtn = document.getElementById('gallery-edit-btn');
            if (editBtn) {
                editBtn.addEventListener('click', function () {
                    editMode = !editMode;
                    var gp = document.querySelector('.gallery-page');
                    if (editMode) {
                        gp.classList.add('editing');
                        editBtn.classList.add('active');
                    } else {
                        gp.classList.remove('editing');
                        editBtn.classList.remove('active');
                    }
                });
            }

            document.querySelectorAll('.cat-removable').forEach(function (h2) {
                h2.addEventListener('click', function () {
                    if (!editMode) return;
                    deleteCategory(this);
                });
            });

            var delBtn = document.querySelector('.lightbox-delete-btn');
            if (delBtn) {
                delBtn.addEventListener('click', function () {
                    var img = images[lbIdx];
                    if (!img) {
                        return;
                    }
                    if (!confirm(this.getAttribute('data-confirm'))) {
                        return;
                    }
                    deleteImage(lbIdx, function () {
                        if (images.length === 0) {
                            closeLightbox();
                            return;
                        }
                        if (lbIdx >= images.length) {
                            lbIdx = images.length - 1;
                        }
                        openLightbox(lbIdx);
                    });
                });
            }
        </script>
    </body>
</html>
