# Git Deployment, WordPress Installer, and Protected Directories

## 1. Overview
Phase 13 introduces three critical hosting features designed with uncompromising security boundaries:
1. **Git Deployments**: Zero-shell PHP key generation, atomic symlink swaps (`releases/` &rarr; `current`), allow-listed unprivileged build steps, and inbound webhooks.
2. **WordPress 1-Click Installer**: Automated MariaDB database provisioning, WP-CLI core configuration with unique cryptographic salts, file permission lockdowns, and the WordPress Nginx security profile.
3. **Password-Protected Directories**: HTTP Basic Authentication (`auth_basic`) with `.htpasswd` files stored strictly outside public web roots and atomically compiled into Nginx server blocks.

---

## 2. Git Deployments

### Architecture & Atomic Release Swaps
Deployments follow an atomic symlink architecture:
```
/home/<username>/apps/<app>/
├── releases/
│   ├── 20261021120000/    (immutable release clone)
│   └── 20261022143000/    (active release)
└── current -> releases/20261022143000/
```

- When `git.deploy` executes:
  1. A target directory `/home/<username>/.../releases/<release_tag>` is initialized.
  2. The repository is cloned via `git clone --depth 1 --branch <branch>`.
  3. Predefined build steps are executed strictly as the unprivileged account user.
  4. Ownership is enforced via `chown -R <username>:<username>`.
  5. An atomic symlink switch is performed via `os.Symlink` to a temporary symlink, followed by `os.Rename`.
- When `git.rollback` executes:
  1. The agent verifies the target release tag exists under `releases/`.
  2. The `current` symlink is atomically repointed to the target release folder.

### Safe Build Steps Allow-List
In compliance with Rule 2 and Rule 4, arbitrary user shell scripts are strictly prohibited. The Go agent validates build steps against a hardcoded dictionary:
```go
var allowedBuildSteps = map[string]string{
    "composer_install": "composer install --no-dev --optimize-autoloader --no-interaction",
    "npm_build":        "npm ci && npm run build",
    "artisan_migrate":  "php artisan migrate --force",
    "artisan_cache":    "php artisan config:cache && php artisan route:cache",
}
```

### Deploy Keys & Webhooks
- **SSH Keypair Generation**: Generated in pure PHP OpenSSL without external shell commands. Public keys are OpenSSH formatted (`ssh-rsa AAAAB3NzaC1yc2E...`), while private keys are encrypted at rest using Laravel model attribute encryption (`$casts = ['private_key' => 'encrypted']`).
- **Webhook Endpoint**: `POST /api/v1/git-deploy/{secret}`:
  - Throttled at 60 req/min.
  - Verifies `auto_deploy` is enabled and account is active.
  - Enqueues `ExecuteGitDeployJob` in the background.

---

## 3. WordPress Installer

### Automated Provisioning
When installing WordPress:
1. Panel allocates a MariaDB database and user (`<user>_wp*` and `<user>_u*`) and dispatches DB creation and grant jobs.
2. The agent action `wordpress.install` downloads WordPress core via WP-CLI as the unprivileged user.
3. Generates `wp-config.php` with unique cryptographic salts and database credentials.
4. Executes `wp core install` with admin credentials, site title, and site URL.
5. Locks down permissions: directories `0755`, files `0644`, and `wp-config.php` `0600`.
6. Updates the domain's virtual host profile to `AppProfile::WORDPRESS` and atomically reloads Nginx.

### Nginx WordPress Profile Hardening
The virtual host template enforces:
- Deny all direct access to `/wp-config.php` and `/xmlrpc.php`.
- Disables PHP execution inside `/wp-content/uploads/`.
- FastCGI buffering (`16 16k`, buffer size `32k`).

### Clean Uninstallation
`wordpress.uninstall` deletes all files in the target directory and optionally drops the associated MariaDB database and user. The domain's virtual host profile is reverted to `php-generic`.

---

## 4. Password-Protected Directories

### Security Guarantees
- **Location Isolation**: Htpasswd files are **never** stored inside `public_html` or any document root. They are strictly saved to `/home/<username>/.htpasswds/<filename>`.
- **Permissions**: Directory `/home/<username>/.htpasswds` is set to `0750` owned by `<username>:www-data`. Individual `.htpasswd` files are set to `0640` owned by `<username>:www-data`.
- **Nginx Integration**: When a protected directory is configured, `SyncProtectedDirectoryJob` updates the htpasswd file via `htpasswd.write` and re-renders the Nginx virtual host with the corresponding `auth_basic` block:
```nginx
location /admin {
    auth_basic "Restricted Area";
    auth_basic_user_file /home/alicecorp/.htpasswds/alicecorp.com_admin_xyz123.htpasswd;
    try_files $uri $uri/ /index.php?$query_string;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm-alicecorp.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
    }
}
```

---

## 5. Agent Actions Reference

| Action | Description | Key Parameters |
| :--- | :--- | :--- |
| `git.deploy` | Clones repo, runs safe build steps, and updates `current` symlink | `username`, `repo_url`, `branch`, `deploy_path`, `private_key`, `build_steps`, `release_tag` |
| `git.rollback` | Atomically points `current` symlink back to a previous release tag | `username`, `deploy_path`, `target_release_tag` |
| `wordpress.install` | Runs WP-CLI core download, config create, and core install | `username`, `domain`, `install_path`, `admin_user`, `admin_password`, `admin_email`, `db_name`, `db_user`, `db_password` |
| `wordpress.uninstall` | Deletes WordPress files and drops associated database | `username`, `install_path`, `remove_db`, `db_name`, `db_user` |
| `htpasswd.write` | Atomically writes htpasswd user:hash entries to `/home/<user>/.htpasswds/` | `username`, `filename`, `entries` |
| `htpasswd.delete` | Removes htpasswd file from `/home/<user>/.htpasswds/` | `username`, `filename` |

