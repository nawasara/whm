# Nawasara WHM

Dashboard manajemen WHM/cPanel untuk Nawasara — kelola akun hosting OPD, email accounts, dan operasional mail server tanpa buka WHM langsung.

## Fitur

### Hosting
- **Account Management** — list, create, suspend, unsuspend, terminate, change password
- **Server Status** — load average, service status, disk usage, WHM version
- **Package Management** — list, create, delete hosting packages
- **Usage Dashboard** — monitoring disk/bandwidth per akun dengan threshold warning (80%) dan critical (95%)

### Email (butuh SSH untuk fitur lanjutan)
- **Email Accounts** — list (DB-cached), create, edit quota, reset password, suspend, delete; bulk actions
- **Mail Queue** — list Exim queue lewat SSH, force/freeze/thaw/bounce/delete dengan delivery log per message
- **Mail Log Search** — search log Exim by date/sender/recipient/message-id/status, dengan trace mode (full event chain per message)
- **Email Stats** — dashboard real-time: received/delivered/bounced/deferred/spam, trend chart 3-30 hari, top senders/domain, hourly volume
- **Mail Security** — rejected SMTP analysis: kategorisasi auth_fail/RBL/unknown_user/spam, top blocked IPs, top targeted accounts (deteksi brute force)

### Cross-cutting
- **Registry Integration** — setiap akun cPanel otomatis jadi `hosting_account` asset dengan OPD/PIC tagging
- **Multi-server** — satu dashboard untuk banyak server WHM (role `hosting`/`mail`/`both`)
- **DB cache + queue pattern** — list view fast (dari DB snapshot), mutation lewat queue, sync via scheduler hourly
- **Audit log** — setiap mutation tercatat di `/admin/sync/jobs` dengan user, action, payload (sensitive masked)

---

## Setup API Token WHM

WHM API Token dipakai untuk semua operasi yang lewat HTTP API (account/email/package management). Lebih aman dari password root karena bisa di-revoke + scope-restricted + IP-restricted.

### Langkah 1 — Login ke WHM

```
https://your-server.com:2087
```

Login sebagai `root` atau user reseller yang punya akses API.

### Langkah 2 — Buka Menu API Tokens

```
Home » Development » Manage API Tokens
```

### Langkah 3 — Generate Token

1. Klik **Generate Token**
2. Form:
   - **Token Name**: `nawasara`
   - **IP Address Restrictions** (opsional, sangat disarankan): IP server Nawasara
   - **Expiration**: `Does Not Expire` untuk integrasi permanent
3. **Privileges** — minimal:

   | Privilege | Wajib? | Alasan |
   |-----------|--------|--------|
   | List Accounts | ✅ | Daftar cPanel account |
   | Create / Modify / Suspend / Terminate Account | ✅ | CRUD account |
   | List / Add / Edit / Kill Package | ✅ | Package management |
   | Show Account Summary | ✅ | Detail account |
   | Basic WHM Functions | ✅ | Version, service, load |
   | **Email** (`Email::*`) | ✅ untuk fitur Email Accounts | UAPI: list_pops_with_disk, add_pop, delete_pop, passwd_pop, edit_pop_quota, suspend_*, unsuspend_* |

   Praktis: pilih **All Features** kalau user adalah root admin.

4. **Save** → token muncul **sekali**, langsung copy.

### Langkah 4 — Simpan di Vault Nawasara

1. **Vault → Credentials → WHM / cPanel → + Tambah Instance**
2. Form:
   - **Nama Instance**: `WHM-Ryder`, `cpanel-kominfo`, dll
   - **Host**: `https://cpanel.ponorogo.go.id:2087`
   - **Username**: `root` (atau user yang generate token)
   - **API Token**: paste token
   - **Server Role**: `hosting` / `mail` / `both`
     - `hosting`: server cPanel websites
     - `mail`: server email (`Email Accounts`, `Mail Queue`, `Mail Log`, `Email Stats`, `Mail Security` akan auto-pilih server ini)
     - `both`: server multi-purpose
3. (Opsional) Field SSH — wajib untuk fitur Mail Queue/Log/Stats/Security. Lihat bagian **Setup SSH** di bawah.
4. **Simpan**

### Langkah 5 — Verifikasi

1. Buka **WHM Hosting → Accounts** → akun cPanel harus muncul
2. Buka **WHM Hosting → Email Accounts** → email muncul setelah klik "Sync Sekarang" pertama kali

Common errors:
- **Unauthorized** → token salah / expired
- **Connection refused** → host/port salah, atau IP restriction blocking IP server Nawasara
- **SSL error** → package sudah set `withoutVerifying()`, jadi self-signed cert tidak masalah

---

## Setup SSH (untuk Mail Queue / Log / Stats / Security)

Empat fitur mail-ops itu pakai **SSH ke server mail**, karena Exim queue & log access lewat HTTP API terbatas. Kalau cuma butuh Email Account CRUD, SSH boleh skip.

### Langkah 1 — Cek port SSH server mail

Login ke server mail via SSH dengan tools yang biasa kamu pakai (PuTTY/terminal), lalu:

```bash
ss -tlnp | grep sshd
# atau
grep -E "^Port" /etc/ssh/sshd_config
```

Catat port — biasanya `22`, kadang custom (`2222`, `6416`, dll).

### Langkah 2 — Generate SSH key dedicated untuk Nawasara

Jangan pakai key root yang sudah ada — generate baru biar bisa di-revoke kapan saja.

```bash
# Di server mail
ssh-keygen -t ed25519 -C "nawasara@nawasara-dev" -f ~/.ssh/nawasara_id -N ""
```

Hasil:
- `~/.ssh/nawasara_id` → **private key** (yang akan disimpan di Vault)
- `~/.ssh/nawasara_id.pub` → public key

### Langkah 3 — Pasang public key ke authorized_keys

```bash
cat ~/.ssh/nawasara_id.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

### Langkah 4 — Copy private key

```bash
cat ~/.ssh/nawasara_id
```

Output (copy semuanya, **termasuk** baris `-----BEGIN ...` dan `-----END ...`):

```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAA...
...
-----END OPENSSH PRIVATE KEY-----
```

### Langkah 5 — Isi field SSH di Vault

Edit instance WHM → field SSH:

| Field | Isi |
|-------|-----|
| **SSH Host** (opsional) | kosongkan kalau host SSH = host WHM (auto-extract dari URL WHM) |
| **SSH Port** | hasil step 1 (default `22`) |
| **SSH User** | biasanya `root` |
| **SSH Private Key (PEM)** | paste private key step 4 (textarea, multi-line) |

Save.

### Langkah 6 — Verifikasi

Via tinker:
```bash
php artisan tinker --execute='
$exim = app(\Nawasara\Whm\Services\EximClient::class)->forInstance("WHM-Ryder");
echo "test: " . ($exim->testConnection() ?? "OK") . PHP_EOL;
echo "version: " . $exim->version() . PHP_EOL;
echo "queue: " . $exim->getQueueCount() . PHP_EOL;
'
```

Atau langsung buka **WHM Hosting → Mail Queue** — kalau berhasil, queue tampil. Kalau gagal, halaman akan kasih hint apa yang salah.

---

## Multi-Server Setup

Tambah instance baru di Vault untuk tiap server. Tiap page yang role-aware (Email/Queue/Stats/Security/Account/dll) auto-filter server yang match role-nya, dan munculkan dropdown "Server" untuk switch.

Setiap server **harus punya API token sendiri** — jangan share token antar server.

---

## Sync ke Registry

Akun cPanel otomatis di-sync ke Registry sebagai asset `hosting_account`:

- **Scheduler**: `whm:sync-accounts` jalan tiap 30 menit
- **Manual**: `php artisan whm:sync-accounts`
- **Email accounts**: `whm:sync-emails` tiap jam (basic) + dailyAt 02:00 dengan `--with-disk` (heavy)
- **Linking**: saat buat akun via dashboard, asset otomatis terbuat dengan OPD/PIC dari form
- **Deactivation**: akun yang dihapus dari WHM akan di-mark `inactive` di registry (tidak dihapus permanen)

---

## Permissions

Setelah install / update, run:
```bash
php artisan db:seed --class="Nawasara\Whm\Database\Seeders\PermissionSeeder" --force
```

| Permission | Fungsi |
|------------|--------|
| **Account** ||
| `whm.account.view` | Lihat list akun |
| `whm.account.create` | Buat akun baru |
| `whm.account.suspend` | Suspend/unsuspend |
| `whm.account.terminate` | Hapus akun permanen |
| `whm.account.manage` | Change password, change package |
| **Package** ||
| `whm.package.view` | Lihat list package |
| `whm.package.manage` | Create/delete package |
| **Server** ||
| `whm.server.view` | Lihat server status |
| `whm.server.manage` | Future: restart service, etc. |
| **Email** ||
| `whm.email.view` | Lihat list email account |
| `whm.email.create` | Tambah email account |
| `whm.email.manage` | Edit quota, reset password, suspend, delete |
| **Mail Queue** ||
| `whm.mailqueue.view` | Lihat queue Exim |
| `whm.mailqueue.manage` | Force/freeze/thaw/bounce/delete message |
| **Mail Log** ||
| `whm.maillog.view` | Search & trace mail log |
| **Email Stats** ||
| `whm.emailstats.view` | Lihat dashboard stats |
| **Spam / Mail Security** ||
| `whm.spam.view` | Lihat reject log analysis |
| `whm.spam.manage` | Future: blacklist/whitelist edit |
| **System** ||
| `whm.ssh.execute` | Gating untuk operasi via SSH |
| `whm.sync.execute` | Run manual sync |
| **Session (cross-package — webmail SSO bridge)** ||
| `whm.session.create` | Internal: panggil API `create_user_session` |
| `webmail.session.launch` | User-facing webmail auto-login (default-attached ke role `guest` + `developer`) |
| `webmail.session.audit.view` | Audit-only — lihat history launch tanpa kemampuan launch (compliance reviewer) |
| `webmail.session.launch_as` | Admin impersonation: buka webmail user manapun. **Sensitive** — manual assign per admin |
| `whm.cpanel.launch_as` | Admin impersonation: buka cPanel akun manapun (full hosting control). **Sensitive** — separate dari webmail.* |

Semua permission otomatis ter-assign ke role `developer`.

> **Catatan permission webmail.\* dan whm.cpanel.launch_as:** namespace `webmail.*` di-share dengan controller di `nawasara/core` (`WebmailLaunchController`) tapi declared di sini karena WHM API yang mint Roundcube session. Kalau install nawasara/core tanpa nawasara/whm, permission `webmail.*` tidak akan ada di DB dan launch akan ditolak — install kedua package, atau hapus tombol launch dari UI.

---

## Audit Log

Setiap mutation (buat email, ganti password, suspend, hapus, dll) tercatat di **`/admin/sync/jobs`** dengan:
- Service + action (label human-readable)
- Target ID
- User yang trigger (nama + email)
- Status (queued/running/success/failed/conflict)
- Payload — **sensitive field (password, token, key) di-mask sebagai `***`** sebelum disimpan
- Trigger source (manual / scheduler)
- Duration & timestamp

Filter by user, by service, by status untuk investigasi cepat.

---

## Troubleshooting

### WHM API

| Problem | Cause | Fix |
|---------|-------|-----|
| `Unauthorized` | Token salah / expired / revoked | Generate ulang di WHM, update di Vault |
| `Connection refused` | Host/port salah, atau IP firewall block | Test `telnet host 2087` dari server Nawasara |
| `cURL error 60 SSL` | Self-signed cert | Sudah handle by `withoutVerifying()` — tetap error berarti firewall outbound block |
| `cURL error 28 timed out` | Server lambat / network slow | Naikkan `timeout` di `config/nawasara-whm.php` |
| `listaccts` empty | Token user tidak punya akses ke akun | Login sebagai root atau reseller dengan akun di bawahnya |

### SSH (Exim ops)

| Problem | Cause | Fix |
|---------|-------|-----|
| `SSH credentials belum di-set di Vault` | Field SSH belum diisi | Isi di Vault → instance → field SSH |
| `SSH authentication gagal` | Public key belum dipasang di server / key invalid | Cek `cat ~/.ssh/authorized_keys` di server, pastikan public key Nawasara ada |
| `Invalid SSH private key` | Format PEM salah / newline corrupt | Pastikan paste lengkap dengan `-----BEGIN/END-----` lines, pakai textarea (bukan single-line) |
| Connection timeout | Port SSH custom / firewall block | Cek `ss -tlnp \| grep sshd` di server, sesuaikan SSH Port di Vault |
| `Permission denied` saat `exim -Mrm` / `exim -Mf` | SSH user bukan root, dan tidak ada sudo | Either pakai user `root`, atau grant sudo NOPASSWD untuk binary `/usr/sbin/exim` |
| Mail Queue/Log empty | Path log non-default | Override `EximClient::DEFAULT_MAINLOG` constant atau pass `path` di `searchLog()` filters |

### Performance

| Symptom | Tuning |
|---------|--------|
| Page Email Accounts lambat | Lihat di `/admin/sync/jobs` apakah `sync_emails` finish — kalau pending, queue worker mati. Run `php artisan queue:work` |
| Mail Log search lambat | Naikkan `limit` filter? Default 200, kasih lebih kecil. SSH timeout `60s` cukup untuk log GB. |
| Stats trend kosong di hari sebelumnya | Log Exim daily-rotated; aggregator sudah baca `*.gz`. Kalau tetap kosong, cek `ls /var/log/exim_mainlog*` di server |
