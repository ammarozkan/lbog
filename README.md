# lbog – Post Publishing System

lbog is a self-contained post publishing system with multi-language support
(TR, EN, FR), account-based permissions, rich text editing with image
upload/gallery, and a trending sidebar. It uses plain procedural PHP with
PostgreSQL via PDO and runs behind Nginx + PHP-FPM.

## Prerequisites

- Nginx
- PHP 8.x with `pdo_pgsql` and `fileinfo` extensions
- PHP-FPM
- PostgreSQL

## Directory Layout (after deployment)

```
/var/www/lbog/
├── src/                 # Web root – all PHP scripts
├── config/
│   └── nginx.conf       # Nginx server block → sites-enabled/lbog
├── data/
│   ├── sessions/        # PHP session files (733, owned by php-fpm user)
│   └── uploads/         # Uploaded images (733, owned by php-fpm user)
├── lang/                # Translation files (en, tr, fr)
├── ARCHITECTURE.md
└── README.md
```

`src/` and `config/` are owned by `root`. `data/sessions/` and `data/uploads/`
must be writable by the PHP-FPM process user (typically `nobody`).

## Setup Steps

### 1. Nginx main config

If you don't have an `/etc/nginx/nginx.conf` yet, copy the provided minimal one:

```bash
sudo cp main-nginx.conf /etc/nginx/nginx.conf
```

Otherwise, ensure your existing nginx.conf's `http` block contains:

```
include /etc/nginx/sites-enabled/*;
```

(This line is usually already present.) It tells Nginx to load any server blocks
placed in `sites-enabled/`.

### 2. Deploy project files

Copy the repository contents to `/var/www/lbog`:

```bash
sudo mkdir -p /var/www/lbog
sudo cp -r src config data lang /var/www/lbog/
```

Set ownership and permissions:

```bash
sudo chown -R root:root /var/www/lbog
sudo chmod 755 /var/www/lbog/src
sudo chmod 750 /var/www/lbog/config

sudo mkdir -p /var/www/lbog/data/sessions /var/www/lbog/data/uploads
sudo chmod 733 /var/www/lbog/data/sessions
sudo chmod 733 /var/www/lbog/data/uploads
sudo chown nobody:nobody /var/www/lbog/data/sessions
sudo chown nobody:nobody /var/www/lbog/data/uploads
```

Adjust `nobody` to match the user your PHP-FPM pool runs as. In my
case, it was the user 'http'. Can be checked with ```ps axu``` while
PHP-FPM is running, or from the php.ini config.

### 3. Nginx server block

Symlink the project's Nginx config into `sites-enabled/`:

```bash
sudo ln -sf /var/www/lbog/config/nginx.conf /etc/nginx/sites-enabled/lbog
```

Verify and reload:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

The server block in `config/nginx.conf` expects PHP-FPM to be listening on
`unix:/run/php-fpm/php-fpm.sock`. If your distribution uses a different socket
path, edit `config/nginx.conf` and update the `fastcgi_pass` directives before
reloading.

### 4. PHP-FPM

PHP-FPM must be installed and running. The pool configuration
(`/etc/php/php-fpm.d/www.conf` or similar) can keep its defaults. The required
PHP extensions are `pdo_pgsql` and `fileinfo`.

### 5. PostgreSQL

#### 5.1 Role and database

Create the PostgreSQL role and database:

```sql
CREATE ROLE lbog WITH LOGIN PASSWORD 'your_password';
CREATE DATABASE lbog OWNER lbog;
```

Alternatively, keep the default `user=postgres` connection (see next section)
and create the database directly.

#### 5.2 Connection via Unix socket

lbog connects to PostgreSQL over a Unix socket. The DSN in `src/db.php` is:

```
pgsql:host=/var/run/postgresql;dbname=lbog
```

The socket path `/var/run/postgresql` is the default on many distributions.
PHP connects as the `postgres` role with an empty password. For this to work,
`pg_hba.conf` must allow local peer or trust authentication for the `postgres`
role:

```
local   all   postgres   peer
```

If your PostgreSQL socket is at a different path (e.g. `/tmp`), update the
`host=` parameter in `src/db.php`.

The database name can be overridden with the environment variable `DB_NAME`.

### 6. Initialize tables

```bash
php /var/www/lbog/src/manage.php migrate
```

This runs the idempotent migration in `src/db.php` which creates all required
tables (`posts`, `accounts`, `trending`, `logs`, `login_attempts`,
`categories`, `images`).

### 7. Create an admin account

```bash
php /var/www/lbog/src/manage.php create <username>
```

The script will prompt for the password interactively (hidden input). To create
the account as an admin directly, add `--admin`:

```bash
php /var/www/lbog/src/manage.php create <username> --admin
```

Also with admin account, managing other accounts from the website will be 
significantly easier than the manage.php CLI.

### 8. Log in

Navigate to `/manage` in your browser. This URL is not linked anywhere on the
site — you must type it manually. Log in with the account you just created. If
the account has the required permissions, you can access the editor and
management tools from there.

## URLs

| Path            | File                    | Description                        |
|-----------------|-------------------------|------------------------------------|
| `/`             | `src/index.php`         | Homepage with featured post        |
| `/post?id=N`    | `src/post.php`          | Single post view                   |
| `/edit`         | `src/edit.php`          | Rich text editor                   |
| `/manage`       | `src/manage.php`        | Login / account management / tools |
| `/trending`     | `src/trending.php`      | Manage trending posts              |
| `/gallery`      | `src/gallery.php`       | Image gallery with lightbox        |
| `/upload`       | `src/upload.php`        | Image upload API (POST)            |
| `/uploads/`     | `data/uploads/`         | Static image files (blocked)       |

Direct access to any `*.php` file returns 404.
