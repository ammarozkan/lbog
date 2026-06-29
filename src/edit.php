<?php

require __DIR__ . '/db.php';

$error = '';
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_post = null;
if ($edit_id) {
    $stmt = db()->prepare("SELECT id, title, content FROM posts WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_post = $stmt->fetch();
}

if ($_POST) {
    verify_csrf();
}

if (!$account || !has_perm($account, PERM_POST_CREATOR)) {
    header('Location: /manage');
    exit;
}

if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id && has_perm($account, PERM_POST_EDITOR)) {
        db()->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
        log_action($account['id'], 'post_delete', "post #{$id}");
    }
    header('Location: /');
    exit;
}

$categories = db()
    ->query("SELECT name FROM categories ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
$recent_images = db()
    ->query(
        "SELECT i.filename, i.description, i.created_at, c.name AS category"
        . " FROM images i"
        . " LEFT JOIN categories c ON i.category_id = c.id"
        . " ORDER BY i.created_at DESC LIMIT 20"
    )
    ->fetchAll();
$images_by_cat = [];
$first = true;
foreach ($recent_images as $img) {
    $cat = $img['category'] ?? __('Uncategorized');
    if (!isset($images_by_cat[$cat])) {
        $images_by_cat[$cat] = [];
    }
    $img['newest'] = $first;
    $first = false;
    $images_by_cat[$cat][] = $img;
}

if (isset($_POST['publish'])) {
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($title === '') {
        $error = __('Title is required.');
    } elseif ($content === '') {
        $error = __('Content is required.');
    } else {
        if ($id) {
            if (!has_perm($account, PERM_POST_EDITOR)) {
                $error = __('Not authorized.');
            } else {
                db()->prepare("UPDATE posts SET title = ?, content = ? WHERE id = ?")->execute([$title, $content, $id]);
                log_action($account['id'], 'post_update', "post #{$id}");
                header('Location: /post?id=' . $id);
                exit;
            }
        } else {
            $stmt = db()->prepare("INSERT INTO posts (title, content, author_id) VALUES (?, ?, ?) RETURNING id");
            $stmt->execute([$title, $content, $account['id']]);
            $published_id = $stmt->fetchColumn();
            log_action($account['id'], 'post_create', "post #{$published_id}");
            header('Location: /');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title><?= $edit_post ? __('Edit Post') : __('New Post') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
        <link rel="stylesheet" href="/style.css">
        <style>
            .editor {
                border: none;
                padding: 0;
                background: none;
            }
            .editor .ql-editor {
                min-height: 50vh;
                font-size: 1rem;
                line-height: 1.8;
            }
            .editor .ql-container {
                border: 1px solid #ddd;
                border-radius: 0 0 4px 4px;
            }
            .editor .ql-toolbar {
                border: 1px solid #ddd;
                border-bottom: none;
                border-radius: 4px 4px 0 0;
            }

            /* Quill i18n overrides */
            .ql-snow .ql-picker.ql-header .ql-picker-label::before,
            .ql-snow .ql-picker.ql-header .ql-picker-item::before {
                content: '<?= addslashes(__('Normal')) ?>';
            }
            .ql-snow .ql-picker.ql-header .ql-picker-label[data-value="1"]::before,
            .ql-snow .ql-picker.ql-header .ql-picker-item[data-value="1"]::before {
                content: '<?= addslashes(__('Heading 1')) ?>';
            }
            .ql-snow .ql-picker.ql-header .ql-picker-label[data-value="2"]::before,
            .ql-snow .ql-picker.ql-header .ql-picker-item[data-value="2"]::before {
                content: '<?= addslashes(__('Heading 2')) ?>';
            }
            .ql-snow .ql-tooltip::before {
                content: "<?= addslashes(__('Visit URL:')) ?>";
            }
            .ql-snow .ql-tooltip a.ql-action::after {
                content: '<?= addslashes(__('Edit')) ?>';
            }
            .ql-snow .ql-tooltip a.ql-remove::before {
                content: '<?= addslashes(__('Remove')) ?>';
            }
            .ql-snow .ql-tooltip.ql-editing a.ql-action::after {
                content: '<?= addslashes(__('Save')) ?>';
            }
            .ql-snow .ql-tooltip[data-mode=link]::before {
                content: "<?= addslashes(__('Enter link:')) ?>";
            }
        </style>
    </head>
    <body>
        <div class="editor-container">
            <div class="editor-header">
                <a href="/"><?= __('Home') ?></a>
                <a href="/gallery"><?= __('Gallery') ?></a>
                <form method="post" class="inline-form">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <button class="link-btn" type="submit" name="logout"><?= __('Log Out') ?></button>
                </form>
            </div>
            <?php if ($error): ?>
                <div class="msg error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" id="editor-form">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <?php if ($edit_post): ?>
                    <input type="hidden" name="id" value="<?= $edit_post['id'] ?>">
                <?php endif; ?>
                <input type="text" name="title" placeholder="<?= __('Title') ?>" required autofocus value="<?= htmlspecialchars($edit_post['title'] ?? '') ?>">
                <div id="editor" class="editor"><?= $edit_post ? clean_content($edit_post['content']) : '' ?></div>
                <input type="hidden" name="content" id="content">
                <br>
                <div class="action-row">
                    <button class="publish" type="submit" name="publish"><?= $edit_post ? __('Update') : __('Publish') ?></button>
                    <?php if ($edit_post && has_perm($account, PERM_POST_EDITOR)): ?>
                        <button class="delete" type="submit" name="delete" onclick="return confirm('<?= __('Delete this post?') ?>')">&#128465;</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($recent_images): ?>
            <div class="mini-gallery">
                <h3><?= __('Images') ?></h3>
                <?php foreach ($images_by_cat as $cat_name => $imgs): ?>
                    <div class="mini-gallery-category">
                        <h4><?= htmlspecialchars($cat_name) ?></h4>
                        <div class="mini-gallery-grid">
                            <?php foreach ($imgs as $img): ?>
                                <div class="mini-gallery-item<?= $img['newest'] ? ' newest' : '' ?>" onclick="insertImage('/image?file=<?= htmlspecialchars($img['filename']) ?>')" title="<?= htmlspecialchars($img['description']) ?>">
                                    <img src="/image?file=<?= htmlspecialchars($img['filename']) ?>" alt="<?= htmlspecialchars($img['description']) ?>" loading="lazy">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="upload-overlay" class="upload-overlay" style="display:none">
            <div class="upload-dialog">
                <h3><?= __('Upload Image') ?></h3>
                <div class="upload-preview" id="upload-preview"></div>
                <input type="text" id="upload-category" list="cat-list" placeholder="<?= __('Category') ?>" autocomplete="off">
                <datalist id="cat-list">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>">
                    <?php endforeach; ?>
                </datalist>
                <textarea id="upload-description" placeholder="<?= __('Description') ?>" rows="3"></textarea>
                <div class="upload-dialog-actions">
                    <button class="publish" id="upload-confirm"><?= __('Upload') ?></button>
                    <button class="delete" id="upload-cancel"><?= __('Cancel') ?></button>
                </div>
                <div id="upload-error" class="msg error" style="display:none;margin-top:.75rem"></div>
            </div>
        </div>

        <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
        <script>
            // --- Custom Image Blot ---
            var ImageBlot = Quill.import('formats/image');
            if (!ImageBlot) {
                console.error('Failed to import formats/image');
            }

            function initImg(img) {
                var w = img.closest('.img-wrap');
                if (!w || img._init) {
                    return;
                }
                img._init = true;

                function size() {
                    var nw = img.naturalWidth, nh = img.naturalHeight;
                    if (!nw) {
                        return;
                    }
                    if (!img.style.width) {
                        var maxW = (w.clientWidth || 600) - 20;
                        img.style.width = (nw > maxW ? maxW : nw) + 'px';
                    }
                    if (!img.style.height) {
                        var iw = parseInt(img.style.width) || nw;
                        img.style.height = Math.round(nh * iw / nw) + 'px';
                    }
                    if (!img.style.left && w.clientWidth) {
                        var iw = parseInt(img.style.width) || nw;
                        img.style.left = Math.round((w.clientWidth - iw) / 2) + 'px';
                    }
                    if (!img.style.top && w.clientHeight) {
                        var ih = img.offsetHeight || nh;
                        img.style.top = Math.round((w.clientHeight - ih) / 2) + 'px';
                    }
                    if (!w.style.width) {
                        w.style.width = img.style.width;
                    }
                    if (!w.style.height) {
                        w.style.height = Math.round(nh * parseInt(img.style.width) / nw) + 'px';
                    }
                }

                if (img.complete) {
                    size();
                } else {
                    img.addEventListener('load', size);
                }
            }

            var ImageWrap = (function () {
                function ImageWrap(node, value) {
                    ImageBlot.call(this, node, value);
                }
                ImageWrap.prototype = Object.create(ImageBlot.prototype);
                ImageWrap.prototype.constructor = ImageWrap;
                ImageWrap.__proto__ = ImageBlot;
                ImageWrap.blotName = 'image';
                ImageWrap.tagName = 'span';
                ImageWrap.className = 'img-wrap';

                ImageWrap.create = function (value) {
                    var node = document.createElement('span');
                    node.className = 'img-wrap';
                    node.setAttribute('contenteditable', 'false');

                    if (typeof value === 'object' && value !== null) {
                        if (value.ww) node.style.width = value.ww;
                        if (value.wh) node.style.height = value.wh;
                    }

                    var h = document.createElement('span');
                    h.className = 'img-handle';
                    h.textContent = '⠿';
                    h.contentEditable = 'false';
                    node.appendChild(h);

                    var d = document.createElement('span');
                    d.className = 'img-del-btn';
                    d.textContent = '✕';
                    d.contentEditable = 'false';
                    d.title = '<?= __('Delete this image?') ?>';
                    d.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var blot = Quill.find(node);
                        if (blot) {
                            blot.remove();
                        } else {
                            node.remove();
                        }
                    });
                    node.appendChild(d);

                    var img = document.createElement('img');
                    if (typeof value === 'string') {
                        img.src = value;
                    } else if (value) {
                        img.src = value.url || '';
                        if (value.w) img.style.width = value.w;
                        if (value.h) img.style.height = value.h;
                        if (value.l) img.style.left = value.l;
                        if (value.t) img.style.top = value.t;
                    }
                    img.alt = '';
                    img.draggable = false;
                    node.appendChild(img);

                    var rh = document.createElement('span');
                    rh.className = 'img-resize-handle';
                    rh.contentEditable = 'false';
                    node.appendChild(rh);

                    if (typeof value === 'string' || !value || !value.w) {
                        img.addEventListener('load', function () {
                            initImg(img);
                            setupImageDrag(img);
                            setupImageResize(img);
                        });
                        if (img.complete) {
                            initImg(img);
                            setupImageDrag(img);
                            setupImageResize(img);
                        }
                    } else {
                        // Restored from saved styles — already sized
                        setupImageDrag(img);
                        setupImageResize(img);
                    }
                    return node;
                };

                ImageWrap.value = function (node) {
                    var img = node.querySelector('img');
                    if (!img) return '';
                    var v = { url: img.src };
                    if (img.style.width) v.w = img.style.width;
                    if (img.style.height) v.h = img.style.height;
                    if (img.style.left) v.l = img.style.left;
                    if (img.style.top) v.t = img.style.top;
                    if (node.style.width) v.ww = node.style.width;
                    if (node.style.height) v.wh = node.style.height;
                    return v;
                };

                ImageWrap.match = function (node) {
                    return node.tagName === 'SPAN' && node.classList.contains('img-wrap');
                };

                return ImageWrap;
            })();
            Quill.register(ImageWrap, true);

            // --- Image move & resize helpers ---
            function setupImageDrag(img) {
                if (img._drag) return;
                img._drag = true;
                var w = img.closest('.img-wrap');
                if (!w) return;
                img.addEventListener('mousedown', function (e) {
                    if (e.button !== 0) return;
                    e.preventDefault();
                    e.stopPropagation();
                    var startX = e.clientX, startY = e.clientY;
                    var origLeft = parseInt(img.style.left) || 0;
                    var origTop = parseInt(img.style.top) || 0;
                    var moved = false;
                    function onMove(e) {
                        var dx = e.clientX - startX, dy = e.clientY - startY;
                        if (!moved && Math.abs(dx) < 3 && Math.abs(dy) < 3) return;
                        moved = true;
                        var iw = parseInt(img.style.width) || img.naturalWidth || 0;
                        var ih = parseInt(img.style.height) || img.naturalHeight || 0;
                        var ww = w.clientWidth, wh = w.clientHeight;
                        var minL = Math.min(0, ww - iw), maxL = Math.max(0, ww - iw);
                        var minT = Math.min(0, wh - ih), maxT = Math.max(0, wh - ih);
                        img.style.left = Math.min(maxL, Math.max(minL, origLeft + dx)) + 'px';
                        img.style.top = Math.min(maxT, Math.max(minT, origTop + dy)) + 'px';
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            }

            function setupImageResize(img) {
                if (img._resize) return;
                img._resize = true;
                var w = img.closest('.img-wrap');
                var rh = w && w.querySelector('.img-resize-handle');
                if (!rh) return;
                rh.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var startX = e.clientX, startY = e.clientY;
                    var origW = w.clientWidth, origH = w.clientHeight;
                    var aspect = origW / origH;
                    function onMove(e) {
                        var newW = Math.max(50, origW + (e.clientX - startX));
                        w.style.width = newW + 'px';
                        w.style.height = Math.round(newW / aspect) + 'px';
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            }

            var dragState = null;

            function setupWrapMove(w) {
                if (w._wrapMove) return;
                w._wrapMove = true;
                var h = w.querySelector('.img-handle');
                if (!h) return;
                h.addEventListener('mousedown', function (e) {
                    if (e.button !== 0) return;
                    e.preventDefault();
                    e.stopPropagation();
                    var img = w.querySelector('img');
                    if (!img) return;
                    dragState = { wrap: w, src: img.src, startX: e.clientX, startY: e.clientY, moved: false };
                    w.style.opacity = '0.5';
                    function onMove(e) {
                        if (!dragState) return;
                        if (!dragState.moved && (Math.abs(e.clientX - dragState.startX) > 5 || Math.abs(e.clientY - dragState.startY) > 5)) {
                            dragState.moved = true;
                            w.style.outline = '3px solid orange';
                        }
                    }
                    function onUp(e) {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        w.style.opacity = '';
                        if (!dragState || !dragState.moved) {
                            w.style.outline = '';
                            dragState = null;
                            return;
                        }
                        w.style.outline = '';
                        var inEditor = e.target && e.target.closest('.ql-editor');
                        dragState = null;
                        if (inEditor) {
                            var img = w.querySelector('img');
                            if (!img) return;
                            var src = img.src;
                            var sW = img.style.width, sH = img.style.height, sL = img.style.left, sT = img.style.top;
                            var wW = w.style.width, wH = w.style.height;
                            setTimeout(function () {
                                var range = quill.getSelection(true);
                                var idx = range ? range.index : quill.getLength();
                                var oldBlot = Quill.find(w);
                                if (!oldBlot) return;
                                var oldIdx = getBlotIndex(oldBlot);
                                oldBlot.remove();
                                if (oldIdx >= 0 && idx > oldIdx) idx--;
                                quill.insertEmbed(idx, 'image', src);
                                quill.setSelection(idx + 1);
                                setTimeout(function () {
                                    var leaf = quill.getLeaf(idx);
                                    if (!leaf || !leaf[0] || !leaf[0].domNode) return;
                                    var nw = leaf[0].domNode;
                                    var ni = nw.querySelector('img');
                                    if (ni) {
                                        if (sW) ni.style.width = sW;
                                        if (sH) ni.style.height = sH;
                                        if (sL) ni.style.left = sL;
                                        if (sT) ni.style.top = sT;
                                        setupImageDrag(ni);
                                        setupImageResize(ni);
                                    }
                                    if (wW) nw.style.width = wW;
                                    if (wH) nw.style.height = wH;
                                    setupWrapMove(nw);
                                }, 0);
                            }, 0);
                        }
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            }

            function getBlotIndex(blot) {
                var node = blot.domNode;
                var r = document.createRange();
                r.setStartBefore(node);
                r.collapse(true);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(r);
                var q = quill.getSelection();
                return q ? q.index : -1;
            }

            // --- Init Quill ---
            var quill = new Quill('#editor', {
                modules: {
                    toolbar: [
                        ['bold', 'italic'],
                        [{ header: [1, 2, false] }],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['link', 'image'],
                    ],
                },
                placeholder: '<?= __('Content') ?>',
                theme: 'snow',
            });

            // --- Localize tooltip placeholder ---
            var _posOrig = quill.theme.tooltip.position;
            quill.theme.tooltip.position = function (reference) {
                _posOrig.call(this, reference);
                var ti = this.root.querySelector('.ql-input');
                if (ti) {
                    var ph = '<?= addslashes(__('Enter URL')) ?>';
                    ti.placeholder = ph;
                    ti.setAttribute('data-link', ph);
                }
            };

            // Ensure all existing wraps have handles, del-btns, and initialized images
            quill.root.querySelectorAll('.img-wrap').forEach(function (w) {
                if (!w.querySelector('.img-handle')) {
                    var h = document.createElement('span');
                    h.className = 'img-handle';
                    h.textContent = '⠿';
                    h.contentEditable = 'false';
                    w.insertBefore(h, w.firstChild);
                }
                if (!w.querySelector('.img-del-btn')) {
                    var d = document.createElement('span');
                    d.className = 'img-del-btn';
                    d.textContent = '✕';
                    d.contentEditable = 'false';
                    d.title = '<?= __('Delete this image?') ?>';
                    d.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var blot = Quill.find(w);
                        if (blot) {
                            blot.remove();
                        } else {
                            w.remove();
                        }
                    });
                    w.appendChild(d);
                }
                if (!w.querySelector('.img-resize-handle')) {
                    var rh = document.createElement('span');
                    rh.className = 'img-resize-handle';
                    rh.contentEditable = 'false';
                    w.appendChild(rh);
                }
                var img = w.querySelector('img');
                if (img) {
                    img.draggable = false;
                    initImg(img);
                    setupImageDrag(img);
                    setupImageResize(img);
                }
                setupWrapMove(w);
            });

            // Click on empty area of an image wrap → place cursor before/after the image
            quill.root.addEventListener('click', function (e) {
                var wrap = e.target.closest('.img-wrap');
                if (!wrap) return;
                var img = wrap.querySelector('img');
                if (!img) return;
                if (e.target.closest('.img-handle, .img-del-btn, .img-resize-handle')) return;
                if (e.target === img) return;
                e.preventDefault();
                e.stopPropagation();
                var blot = Quill.find(wrap);
                if (!blot) return;
                var idx = getBlotIndex(blot);
                if (idx < 0) return;
                var r = img.getBoundingClientRect();
                quill.setSelection(e.clientX < r.left ? idx : idx + 1, 0);
            });

            // Tab key: insert em-space
            quill.root.addEventListener('keydown', function (e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var r = quill.getSelection();
                    if (r) {
                        quill.insertText(r.index, '\u2003', Quill.sources.USER);
                    }
                }
            });

            // --- Upload dialog ---
            var uplOverlay = document.getElementById('upload-overlay');
            var uplPreview = document.getElementById('upload-preview');
            var uplCat = document.getElementById('upload-category');
            var uplDesc = document.getElementById('upload-description');
            var uplErr = document.getElementById('upload-error');
            var uplConfirm = document.getElementById('upload-confirm');
            var uplCancel = document.getElementById('upload-cancel');

            function showUploadDialog(file) {
                if (file) {
                    uplPreview.innerHTML = '<img src="' + URL.createObjectURL(file) + '" style="max-width:100%;max-height:200px">';
                } else {
                    uplPreview.innerHTML = '<?= __('Select an image file from your computer.') ?>';
                }
                uplCat.value = '';
                uplDesc.value = '';
                uplErr.style.display = 'none';
                uplOverlay.style.display = 'flex';
                uplConfirm.onclick = function () {
                    if (!file) {
                        return;
                    }
                    doUpload(file);
                };
                uplCancel.onclick = function () {
                    uplOverlay.style.display = 'none';
                };
                uplOverlay.onclick = function (e) {
                    if (e.target === uplOverlay) {
                        uplOverlay.style.display = 'none';
                    }
                };
            }

            // --- File input for direct upload ---
            var uplInput = document.createElement('input');
            uplInput.type = 'file';
            uplInput.accept = 'image/*';
            uplInput.style.display = 'none';
            document.body.appendChild(uplInput);

            uplInput.addEventListener('change', function () {
                if (uplInput.files.length) {
                    showUploadDialog(uplInput.files[0]);
                }
                uplInput.value = '';
            });

            // Toolbar image button opens file picker
            quill.getModule('toolbar').handlers.image = function () {
                uplInput.click();
            };

            function doUpload(file) {
                var cat = uplCat.value.trim();
                var desc = uplDesc.value.trim();
                if (!cat || !desc) {
                    uplErr.textContent = '<?= __('Category and description are required.') ?>';
                    uplErr.style.display = 'block';
                    return;
                }
                uplErr.style.display = 'none';
                uplConfirm.disabled = true;
                uplConfirm.textContent = '<?= __('Uploading...') ?>';
                var fd = new FormData();
                fd.append('_token', '<?= csrf_token() ?>');
                fd.append('image', file);
                fd.append('category', cat);
                fd.append('description', desc);
                fetch('/upload', { method: 'POST', body: fd })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        if (data.error) {
                            uplErr.textContent = data.error;
                            uplErr.style.display = 'block';
                            uplConfirm.disabled = false;
                            uplConfirm.textContent = '<?= __('Upload') ?>';
                            return;
                        }
                        uplOverlay.style.display = 'none';
                        var range = quill.getSelection(true);
                        var idx = range ? range.index : quill.getLength();
                        quill.insertEmbed(idx, 'image', data.url, Quill.sources.USER);
                        quill.setSelection(idx + 1, Quill.sources.USER);
                        uplConfirm.disabled = false;
                        uplConfirm.textContent = '<?= __('Upload') ?>';
                    })
                    .catch(function (e) {
                        uplErr.textContent = '<?= __('Upload failed.') ?>';
                        uplErr.style.display = 'block';
                        uplConfirm.disabled = false;
                        uplConfirm.textContent = '<?= __('Upload') ?>';
                    });
            }

            // Drag & drop from desktop
            quill.root.addEventListener('dragover', function (e) {
                e.preventDefault();
            });

            quill.root.addEventListener('drop', function (e) {
                e.preventDefault();
                if (e.dataTransfer.files.length) {
                    showUploadDialog(e.dataTransfer.files[0]);
                }
            });

            // Image resize via wheel
            quill.root.addEventListener('wheel', function (e) {
                var img = e.target.closest('.img-wrap img');
                if (!img) {
                    return;
                }
                e.preventDefault();
                var w = parseInt(img.style.width) || 0;
                if (!w) {
                    w = (img.naturalWidth || 300);
                    img.style.width = w + 'px';
                    img.style.maxWidth = 'none';
                    var wrap = img.closest('.img-wrap');
                    if (wrap && !wrap.style.width) {
                        wrap.style.width = w + 'px';
                    }
                }
                img.style.maxWidth = 'none';
                w += e.deltaY > 0 ? -20 : 20;
                if (w < 20) {
                    w = 20;
                }
                img.style.width = w + 'px';
                var nw = img.naturalWidth, nh = img.naturalHeight;
                if (nw) {
                    img.style.height = Math.round(nh * w / nw) + 'px';
                }
            }, { passive: false });

            // Image resize via pinch
            var touchData = null;
            quill.root.addEventListener('touchstart', function (e) {
                var img = e.target.closest('.img-wrap img');
                if (!img) {
                    return;
                }
                e.preventDefault();
                if (e.touches.length === 2) {
                    var t1 = e.touches[0], t2 = e.touches[1];
                    touchData = {
                        img: img,
                        pinch: {
                            dist: Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY),
                            w: parseInt(img.style.width) || img.naturalWidth || 300,
                        },
                    };
                }
            });

            quill.root.addEventListener('touchmove', function (e) {
                if (!touchData || !touchData.pinch) {
                    return;
                }
                e.preventDefault();
                var d = touchData;
                if (e.touches.length === 2) {
                    var t1 = e.touches[0], t2 = e.touches[1];
                    var nd = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
                    var w = Math.round(d.pinch.w * nd / d.pinch.dist);
                    if (w < 20) {
                        w = 20;
                    }
                    d.img.style.width = w + 'px';
                    var nw = d.img.naturalWidth, nh = d.img.naturalHeight;
                    if (nw) {
                        d.img.style.height = Math.round(nh * w / nw) + 'px';
                    }
                }
            }, { passive: false });

            quill.root.addEventListener('touchend', function () {
                touchData = null;
            });

            // Form submit
            document.getElementById('editor-form').addEventListener('submit', function () {
                var html = quill.root.innerHTML;
                html = html.replace(/<span[^>]*class="img-handle"[^>]*>[\s\S]*?<\/span>/g, '');
                html = html.replace(/<span[^>]*class="img-del-btn"[^>]*>[\s\S]*?<\/span>/g, '');
                html = html.replace(/<span[^>]*class="img-resize-handle"[^>]*>[\s\S]*?<\/span>/g, '');
                document.getElementById('content').value = html;
            });

            // Mini-gallery insert image
            function insertImage(url) {
                var range = quill.getSelection(true);
                var idx = range ? range.index : quill.getLength();
                quill.insertEmbed(idx, 'image', url, Quill.sources.USER);
                quill.setSelection(idx + 1, Quill.sources.USER);
            }

            // Prevent toolbar mousedown from stealing focus
            var qlToolbar = document.querySelector('.ql-toolbar');
            if (qlToolbar) {
                qlToolbar.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                });
            }
        </script>
    </body>
</html>
