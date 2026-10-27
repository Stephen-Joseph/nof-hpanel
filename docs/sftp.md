# Module Documentation: Customer SFTP Access & Future Chroot Architecture

## 1. Overview
HPanel provides customers with native SFTP access to their hosting account files over SSH (Port 22) using their Linux username and an SFTP password configured through the panel.

---

## 2. Current SFTP Access Model (Non-Chrooted Phase)

### 2.1 Linux User & Shell Isolation
- Each hosting account corresponds to a dedicated system user (e.g., `admin263`) with home directory `/home/<user>`.
- The user's login shell is set to `/usr/sbin/nologin`. This strictly prevents interactive SSH terminal shells (such as bash/sh).
- When the SFTP client connects, OpenSSH matches the user block and invokes `ForceCommand internal-sftp -u 022`. This runs the SFTP subsystem in-process without invoking `/usr/sbin/nologin`, avoiding the ASCII `"This account is currently not available"` error that breaks SFTP initialization.

### 2.2 Permissions Baseline
- `/home/<user>`: owned by `<user>:<user>`, mode `0711` (`rwx--x--x`).
- `/home/<user>/public_html`: owned by `<user>:<user>`, mode `0755` (`rwxr-xr-x`).
- `/home/<user>/logs`, `/home/<user>/tmp`, `/home/<user>/backups`: mode `0750` (`rwxr-x---`).
- `/home/<user>/.ssh`: mode `0700` (`rwx------`).

### 2.3 Sshd Drop-In Structure
Each account with SFTP access receives a dedicated drop-in file: `/etc/ssh/sshd_config.d/50-hpanel-<user>.conf`:
```sshd
# HPanel SFTP access for <user>
# Note: ChrootDirectory is omitted in this phase due to home ownership (0711).
# A dedicated chroot layout (/var/chroot/<user>) will be introduced in a future release.
Match User <user>
    ForceCommand internal-sftp -u 022
    AllowTcpForwarding no
    X11Forwarding no
    PasswordAuthentication yes
```

### 2.4 Global Authentication Baseline (`00-hpanel.conf`)
OpenSSH evaluates configuration files in alphabetical order within `/etc/ssh/sshd_config.d/`. HPanel deploys `/etc/ssh/sshd_config.d/00-hpanel.conf` so panel settings sort first:
```sshd
# Managed by HPanel - Global SFTP and SSH baseline
PasswordAuthentication yes
KbdInteractiveAuthentication yes
PubkeyAuthentication yes
Subsystem sftp /usr/lib/openssh/sftp-server
```
Additionally, the installer and Go agent idempotently comment out any hardcoded `PasswordAuthentication no` and `KbdInteractiveAuthentication no` directives in `/etc/ssh/sshd_config` (preserving a one-time backup at `/etc/ssh/sshd_config.hpanel.bak`).

---

## 3. Why Direct Home Chroot Cannot Work Today

OpenSSH enforces strict security requirements on the `ChrootDirectory` directive (`sshd_config(5)`):
1. **Ownership**: Every path component of the chroot directory from `/` down to the target directory must be owned by `root:root`.
2. **Permissions**: None of the parent directories or the target directory itself may be writable by any other user or group (maximum permission: `0755`).

Because `/home/<user>` is owned by `<user>:<user>` (mode `0711`), setting:
```sshd
ChrootDirectory /home/%u
# or
ChrootDirectory /home/<user>/public_html
```
causes `sshd` to reject authentication at runtime with:
```
fatal: bad ownership or modes for chroot directory "/home/admin263"
```
Changing `/home/<user>` ownership to `root:root` would break PHP-FPM pool execution, customer file uploads, and permissions isolation for the account.

---

## 4. Future Chroot Architecture (`/var/chroot/<user>`)

> [!NOTE]
> The chroot layout described below is documented for future implementation and is **not enabled** in the current phase.

### 4.1 Target Layout
```
/var/chroot/
  └── <user>/                       [root:root, 0755] (Chroot target)
       └── public_html/             [mount point: bind from /home/<user>/public_html]
```

### 4.2 Provisioning Workflow
When SFTP chroot is provisioned for an account:
1. Create the root-owned jail base:
   ```bash
   mkdir -p /var/chroot/<user>/public_html
   chown root:root /var/chroot/<user>
   chmod 0755 /var/chroot/<user>
   ```
2. Bind-mount the customer's real web directory into the chroot jail:
   ```bash
   mount --bind /home/<user>/public_html /var/chroot/<user>/public_html
   ```
3. Persist the bind mount in `/etc/fstab` so it survives server reboots:
   ```
   /home/<user>/public_html  /var/chroot/<user>/public_html  none  bind,nofail  0  0
   ```
4. Update the SSH drop-in `/etc/ssh/sshd_config.d/50-hpanel-<user>.conf`:
   ```sshd
   Match User <user>
       ChrootDirectory /var/chroot/%u
       ForceCommand internal-sftp -u 022
       AllowTcpForwarding no
       X11Forwarding no
       PasswordAuthentication yes
   ```
5. Validate configuration using `sshd -t` before issuing `systemctl reload ssh`.

### 4.3 Teardown Workflow
When the account is terminated or SFTP access is disabled:
1. Unlink/remove `/etc/ssh/sshd_config.d/50-hpanel-<user>.conf` and reload `ssh`.
2. Unmount the bind directory:
   ```bash
   umount -l /var/chroot/<user>/public_html || true
   ```
3. Remove the line from `/etc/fstab`.
4. Remove the `/var/chroot/<user>` directory structure:
   ```bash
   rm -rf /var/chroot/<user>
   ```

---

## 5. Security Invariants
- **No Direct Shell**: Customers remain restricted to `internal-sftp` with `/usr/sbin/nologin`.
- **Zero Cleartext Passwords**: SFTP passwords are set strictly via `chpasswd` with standard input and never passed via command line flags or recorded in log files.
- **Atomic Rollback**: Any modification to `/etc/ssh/sshd_config` or `/etc/ssh/sshd_config.d/` is validated via `sshd -t`. If validation fails, changes are restored automatically.
- **Admin Access Unaffected**: `PermitRootLogin` and pubkey authentication for the server administrator (`admin`/`ubuntu`/`root`) are preserved without disruption.

