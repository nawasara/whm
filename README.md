# Nawasara WHM

A WHM/cPanel management dashboard for Nawasara. Manage OPD hosting accounts, email accounts, and mail server operations without opening WHM directly.

## Features

### Hosting
- **Account Management**: list, create, suspend, unsuspend, terminate, change password
- **Server Status**: load average, service status, disk usage, WHM version
- **Package Management**: list, create, delete hosting packages
- **Usage Dashboard**: per-account disk and bandwidth monitoring with a warning threshold (80%) and a critical threshold (95%)

### Email (advanced features need SSH)
- **Email Accounts**: list (DB-cached), create, edit quota, reset password, suspend, delete, plus bulk actions
- **Mail Queue**: list the Exim queue over SSH, force/freeze/thaw/bounce/delete, with a delivery log per message
- **Mail Log Search**: search the Exim log by date, sender, recipient, message-id, or status, with a trace mode that shows the full event chain per message
- **Email Stats**: a real-time dashboard for received/delivered/bounced/deferred/spam, a trend chart over 3 to 30 days, top senders and domains, and hourly volume
- **Mail Security**: rejected SMTP analysis, categorizing auth_fail/RBL/unknown_user/spam, top blocked IPs, and top targeted accounts (brute force detection)

### Cross-cutting
- **Registry Integration**: every cPanel account automatically becomes a `hosting_account` asset with OPD/PIC tagging
- **Multi-server**: one dashboard for many WHM servers (role `hosting`, `mail`, or `both`)
- **DB cache plus queue pattern**: list views are fast (served from a DB snapshot), mutations go through the queue, and an hourly scheduler keeps things in sync
- **Audit log**: every mutation is recorded at `/admin/sync/jobs` with user, action, and payload (sensitive fields masked)

---

## WHM API Token Setup

The WHM API Token is used for every operation that goes through the HTTP API (account, email, and package management). It is safer than the root password because it can be revoked, scope-restricted, and IP-restricted.

### Step 1: Log in to WHM

```
https://your-server.com:2087
```

Log in as `root` or a reseller user that has API access.

### Step 2: Open the API Tokens menu

```
Home » Development » Manage API Tokens
```

### Step 3: Generate the token

1. Click **Generate Token**
2. Fill in the form:
   - **Token Name**: `nawasara`
   - **IP Address Restrictions** (optional but strongly recommended): the Nawasara server IP
   - **Expiration**: `Does Not Expire` for a permanent integration
3. **Privileges**, at minimum:

   | Privilege | Required? | Reason |
   |-----------|--------|--------|
   | List Accounts | yes | List cPanel accounts |
   | Create / Modify / Suspend / Terminate Account | yes | Account CRUD |
   | List / Add / Edit / Kill Package | yes | Package management |
   | Show Account Summary | yes | Account detail |
   | Basic WHM Functions | yes | Version, service, load |
   | **Email** (`Email::*`) | yes, for the Email Accounts feature | UAPI: list_pops_with_disk, add_pop, delete_pop, passwd_pop, edit_pop_quota, suspend_*, unsuspend_* |

   In practice, pick **All Features** if the user is a root admin.

4. **Save**. The token appears **once**, so copy it right away.

### Step 4: Store it in Nawasara Vault

1. **Vault -> Credentials -> WHM / cPanel -> + Add Instance**
2. Fill in the form:
   - **Instance Name**: `WHM-Ryder`, `cpanel-kominfo`, and so on
   - **Host**: `https://cpanel.ponorogo.go.id:2087`
   - **Username**: `root` (or the user that generated the token)
   - **API Token**: paste the token
   - **Server Role**: `hosting`, `mail`, or `both`
     - `hosting`: cPanel website server
     - `mail`: email server (`Email Accounts`, `Mail Queue`, `Mail Log`, `Email Stats`, and `Mail Security` will auto-select this server)
     - `both`: multi-purpose server
3. (Optional) SSH fields, required for the Mail Queue/Log/Stats/Security features. See the **SSH Setup** section below.
4. **Save**

### Step 5: Verify

1. Open **WHM Hosting -> Accounts** and confirm the cPanel accounts show up
2. Open **WHM Hosting -> Email Accounts**. Email addresses appear after the first "Sync Now" run.

Common errors:
- **Unauthorized**: the token is wrong or expired
- **Connection refused**: wrong host or port, or an IP restriction is blocking the Nawasara server IP
- **SSL error**: the package already calls `withoutVerifying()`, so a self-signed cert is not a problem

---

## SSH Setup (for Mail Queue / Log / Stats / Security)

Those four mail-ops features use **SSH to the mail server**, because access to the Exim queue and logs over the HTTP API is limited. If you only need Email Account CRUD, you can skip SSH.

### Step 1: Check the mail server's SSH port

Log in to the mail server over SSH with whatever tool you normally use (PuTTY, a terminal), then:

```bash
ss -tlnp | grep sshd
# or
grep -E "^Port" /etc/ssh/sshd_config
```

Note the port. It is usually `22`, sometimes custom (`2222`, `6416`, and so on).

### Step 2: Generate a dedicated SSH key for Nawasara

Do not reuse an existing root key. Generate a new one so it can be revoked at any time.

```bash
# On the mail server
ssh-keygen -t ed25519 -C "nawasara@nawasara-dev" -f ~/.ssh/nawasara_id -N ""
```

Result:
- `~/.ssh/nawasara_id`: the **private key** (this goes into Vault)
- `~/.ssh/nawasara_id.pub`: the public key

### Step 3: Install the public key into authorized_keys

```bash
cat ~/.ssh/nawasara_id.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

### Step 4: Copy the private key

```bash
cat ~/.ssh/nawasara_id
```

Copy the whole thing, **including** the `-----BEGIN ...` and `-----END ...` lines:

```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAA...
...
-----END OPENSSH PRIVATE KEY-----
```

### Step 5: Fill in the SSH fields in Vault

Edit the WHM instance and set the SSH fields:

| Field | Value |
|-------|-----|
| **SSH Host** (optional) | leave blank if the SSH host is the same as the WHM host (auto-extracted from the WHM URL) |
| **SSH Port** | the result of step 1 (default `22`) |
| **SSH User** | usually `root` |
| **SSH Private Key (PEM)** | paste the private key from step 4 (textarea, multi-line) |

Save.

### Step 6: Verify

Via tinker:
```bash
php artisan tinker --execute='
$exim = app(\Nawasara\Whm\Services\EximClient::class)->forInstance("WHM-Ryder");
echo "test: " . ($exim->testConnection() ?? "OK") . PHP_EOL;
echo "version: " . $exim->version() . PHP_EOL;
echo "queue: " . $exim->getQueueCount() . PHP_EOL;
'
```

Or just open **WHM Hosting -> Mail Queue**. If it works, the queue shows up. If it fails, the page gives a hint about what went wrong.

---

## Multi-Server Setup

Add a new instance in Vault for each server. Every role-aware page (Email, Queue, Stats, Security, Account, and so on) auto-filters to the servers matching its role and shows a "Server" dropdown to switch between them.

Each server **must have its own API token**. Do not share a token across servers.

---

## Sync to Registry

cPanel accounts are automatically synced to the Registry as `hosting_account` assets:

- **Scheduler**: `whm:sync-accounts` runs every 30 minutes
- **Manual**: `php artisan whm:sync-accounts`
- **Email accounts**: `whm:sync-emails` runs hourly (basic) plus dailyAt 02:00 with `--with-disk` (heavy)
- **Linking**: when you create an account from the dashboard, the asset is created automatically with the OPD/PIC from the form
- **Deactivation**: an account removed from WHM is marked `inactive` in the registry, not deleted permanently

---

## Permissions

After install or update, run:
```bash
php artisan db:seed --class="Nawasara\Whm\Database\Seeders\PermissionSeeder" --force
```

| Permission | Purpose |
|------------|--------|
| **Account** ||
| `whm.account.view` | View the account list |
| `whm.account.create` | Create a new account |
| `whm.account.suspend` | Suspend and unsuspend |
| `whm.account.terminate` | Delete an account permanently |
| `whm.account.manage` | Change password, change package |
| **Package** ||
| `whm.package.view` | View the package list |
| `whm.package.manage` | Create and delete packages |
| **Server** ||
| `whm.server.view` | View server status |
| `whm.server.manage` | Future: restart service, etc. |
| **Email** ||
| `whm.email.view` | View the email account list |
| `whm.email.create` | Add an email account |
| `whm.email.manage` | Edit quota, reset password, suspend, delete |
| **Mail Queue** ||
| `whm.mailqueue.view` | View the Exim queue |
| `whm.mailqueue.manage` | Force/freeze/thaw/bounce/delete a message |
| **Mail Log** ||
| `whm.maillog.view` | Search and trace the mail log |
| **Email Stats** ||
| `whm.emailstats.view` | View the stats dashboard |
| **Spam / Mail Security** ||
| `whm.spam.view` | View the reject log analysis |
| `whm.spam.manage` | Future: blacklist/whitelist editing |
| **System** ||
| `whm.ssh.execute` | Gate for operations over SSH |
| `whm.sync.execute` | Run a manual sync |
| **Session (cross-package, webmail SSO bridge)** ||
| `whm.session.create` | Internal: call the `create_user_session` API |
| `webmail.session.launch` | User-facing webmail auto-login (attached by default to the `guest` and `developer` roles) |
| `webmail.session.audit.view` | Audit only: view the launch history without the ability to launch (for a compliance reviewer) |
| `webmail.session.launch_as` | Admin impersonation: open any user's webmail. **Sensitive**, assign manually per admin |
| `whm.cpanel.launch_as` | Admin impersonation: open any cPanel account (full hosting control). **Sensitive**, kept separate from webmail.* |

All permissions are automatically assigned to the `developer` role.

> **Note on the webmail.\* and whm.cpanel.launch_as permissions:** the `webmail.*` namespace is shared with a controller in `nawasara/core` (`WebmailLaunchController`) but declared here because it is the WHM API that mints the Roundcube session. If you install nawasara/core without nawasara/whm, the `webmail.*` permissions will not exist in the DB and launching will be denied. Install both packages, or remove the launch button from the UI.

---

## Audit Log

Every mutation (create email, change password, suspend, delete, and so on) is recorded at **`/admin/sync/jobs`** with:
- Service plus action (a human-readable label)
- Target ID
- The user who triggered it (name and email)
- Status (queued/running/success/failed/conflict)
- Payload, with **sensitive fields (password, token, key) masked as `***`** before storage
- Trigger source (manual or scheduler)
- Duration and timestamp

Filter by user, service, or status for quick investigation.

---

## Troubleshooting

### WHM API

| Problem | Cause | Fix |
|---------|-------|-----|
| `Unauthorized` | Token wrong, expired, or revoked | Regenerate in WHM, update in Vault |
| `Connection refused` | Wrong host/port, or an IP firewall block | Test `telnet host 2087` from the Nawasara server |
| `cURL error 60 SSL` | Self-signed cert | Already handled by `withoutVerifying()`. A remaining error means an outbound firewall block |
| `cURL error 28 timed out` | Slow server or network | Raise `timeout` in `config/nawasara-whm.php` |
| `listaccts` empty | The token user has no access to accounts | Log in as root or a reseller with accounts under it |

### SSH (Exim ops)

| Problem | Cause | Fix |
|---------|-------|-----|
| `SSH credentials belum di-set di Vault` | SSH fields not filled in | Fill them in Vault -> instance -> SSH fields |
| `SSH authentication gagal` | Public key not installed on the server, or the key is invalid | Check `cat ~/.ssh/authorized_keys` on the server and confirm the Nawasara public key is there |
| `Invalid SSH private key` | Wrong PEM format or corrupted newlines | Make sure you pasted the full key including the `-----BEGIN/END-----` lines, and use the textarea (not a single line) |
| Connection timeout | Custom SSH port or firewall block | Check `ss -tlnp \| grep sshd` on the server and adjust the SSH Port in Vault |
| `Permission denied` during `exim -Mrm` / `exim -Mf` | The SSH user is not root and there is no sudo | Either use the `root` user, or grant sudo NOPASSWD for the `/usr/sbin/exim` binary |
| Mail Queue/Log empty | Non-default log path | Override the `EximClient::DEFAULT_MAINLOG` constant, or pass `path` in the `searchLog()` filters |

### Performance

| Symptom | Tuning |
|---------|--------|
| Email Accounts page is slow | Check `/admin/sync/jobs` to see whether `sync_emails` finished. If it is pending, the queue worker is down. Run `php artisan queue:work` |
| Mail Log search is slow | Lower the `limit` filter. The default is 200, so try a smaller value. The SSH timeout of `60s` is enough for GB-sized logs. |
| Stats trend empty for previous days | The Exim log is daily-rotated and the aggregator already reads `*.gz`. If it is still empty, check `ls /var/log/exim_mainlog*` on the server |
