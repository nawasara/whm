# Nawasara WHM

Dashboard manajemen WHM/cPanel untuk Nawasara — kelola akun hosting OPD tanpa buka WHM.

## Fitur

- **Account Management** — list, create, suspend, unsuspend, terminate, change password
- **Server Status** — load average, service status, disk usage, WHM version
- **Package Management** — list, create, delete hosting packages
- **Usage Dashboard** — monitoring disk/bandwidth per akun dengan threshold warning (80%) dan critical (95%)
- **Registry Integration** — setiap akun cPanel otomatis jadi `hosting_account` asset dengan OPD/PIC tagging
- **Multi-server** — satu dashboard untuk banyak server WHM
- **Scheduled Sync** — sync akun dari WHM ke registry tiap 30 menit

---

## Cara Membuat API Token WHM

Package ini butuh **WHM API Token** (bukan password root). Token ini lebih aman karena:
- Bisa di-revoke kapan saja tanpa harus ganti password
- Bisa dibatasi hanya untuk IP tertentu
- Scope permission bisa dipersempit ke yang diperlukan saja

### Langkah 1 — Login ke WHM

Buka WHM di browser:
```
https://your-server.com:2087
```

Login sebagai `root` atau user reseller yang punya akses API.

### Langkah 2 — Buka Menu API Tokens

Di WHM, cari menu lewat search bar atas: **"Manage API Tokens"** atau langsung navigasi:

```
Home » Development » Manage API Tokens
```

### Langkah 3 — Create New Token

1. Klik tombol **"Generate Token"**
2. Isi form:
   - **Token Name**: `nawasara` (bebas, yang penting unik)
   - **IP Address Restrictions** (opsional tapi **sangat disarankan**):
     - Isi IP server Nawasara supaya token tidak bisa dipakai dari luar
     - Contoh: `203.0.113.45` atau range `203.0.113.0/24`
     - Kosongkan kalau server Nawasara punya IP dinamis
   - **Expiration**: pilih `Does Not Expire` untuk integrasi permanent, atau set tanggal kalau mau auto-expire
3. **Privileges** — pilih scope permission yang dibutuhkan:

   | Privilege | Wajib? | Alasan |
   |-----------|--------|--------|
   | **List Accounts** | ✅ | Ambil daftar akun cPanel |
   | **Create Account** | ✅ | Fitur "Tambah Akun" |
   | **Modify Account** | ✅ | Change password, change package |
   | **Suspend/Unsuspend Account** | ✅ | Fitur suspend |
   | **Terminate Account** | ✅ | Fitur hapus akun |
   | **List Packages** | ✅ | Dropdown package saat buat akun |
   | **Add/Edit Package** | ✅ | Fitur package management |
   | **Kill Package** | ✅ | Fitur hapus package |
   | **Show Account Summary** | ✅ | Detail akun |
   | **Basic WHM Functions** | ✅ | Version, service status, load, disk |

   Untuk kemudahan, bisa pilih **"All Features"** kalau user memang root admin.

4. Klik **"Save"** → WHM generate token

### Langkah 4 — Copy Token

Token akan muncul **sekali saja**. Format:
```
ABC123DEF456GHI789JKL012MNO345PQR678
```

**⚠️ PENTING**: Copy langsung ke Vault. Kalau hilang harus generate ulang.

### Langkah 5 — Simpan di Vault Nawasara

1. Buka Nawasara → menu **Vault → Credentials**
2. Cari card **"WHM / cPanel"**
3. Klik **"+ Tambah Instance"**
4. Isi form:
   - **Nama Instance**: nama server untuk identifikasi, misal `server-01` atau `cpanel-kominfo-ponorogo`
   - **Host**: URL lengkap WHM, misal `https://cpanel.ponorogo.go.id:2087`
     - Bisa juga cukup hostname: `cpanel.ponorogo.go.id` (otomatis pakai port 2087)
   - **Username**: user yang generate token (biasanya `root`)
   - **API Token**: paste token yang baru digenerate
5. Klik **Simpan**

### Langkah 6 — Verifikasi

1. Buka menu **WHM / cPanel → Accounts** di Nawasara
2. Akun cPanel dari server tersebut harus muncul
3. Kalau error, cek:
   - **"Unauthorized"** → token salah atau expired
   - **"Connection refused"** → host/port salah, atau IP restriction blocking
   - **"SSL error"** → certificate self-signed (package sudah handle ini dengan `withoutVerifying()`)

---

## Multi-Server Setup

Kalau Kominfo kelola banyak server WHM, tambahkan instance baru di Vault untuk tiap server. Di dashboard Accounts/Packages/Usage/Server akan muncul **dropdown "Server"** untuk switch antar server.

Setiap server **harus punya API token sendiri** — jangan pakai token yang sama untuk semua server.

---

## Sync ke Registry

Akun cPanel otomatis di-sync ke Registry sebagai asset `hosting_account`:

- **Scheduler**: `whm:sync-accounts` jalan tiap 30 menit
- **Manual**: `php artisan whm:sync-accounts`
- **Linking**: saat buat akun via dashboard, asset otomatis terbuat dengan OPD/PIC dari form
- **Deactivation**: akun yang dihapus dari WHM akan di-mark `inactive` di registry (tidak dihapus permanen)

---

## Permissions

| Permission | Fungsi |
|------------|--------|
| `whm.account.view` | Lihat list akun |
| `whm.account.create` | Buat akun baru |
| `whm.account.suspend` | Suspend/unsuspend |
| `whm.account.terminate` | Hapus akun permanen |
| `whm.account.manage` | Change password, change package |
| `whm.package.view` | Lihat list package |
| `whm.package.manage` | Create/delete package |
| `whm.server.view` | Lihat server status |
| `whm.server.manage` | Future: restart service, etc. |
| `whm.sync.execute` | Run manual sync |

Setelah install package, jalankan:
```bash
php artisan db:seed --class="Nawasara\Whm\Database\Seeders\PermissionSeeder" --force
```

Semua permission otomatis ter-assign ke role `developer`.

---

## Troubleshooting

### Token valid tapi "listaccts" return empty

User yang generate token tidak punya hak untuk lihat semua akun. Pastikan login sebagai `root` atau reseller yang memang punya akun di bawahnya.

### "cURL error 60: SSL certificate problem"

Package sudah set `withoutVerifying()` — kalau masih error, kemungkinan server firewall block outgoing HTTPS. Check `telnet server-whm 2087` dari server Nawasara.

### Timeout di "listaccts"

Kalau akun sangat banyak (>500), naikkan timeout di config:
```php
// config/nawasara-whm.php
'timeout' => 60,
```

### IP restriction salah set

Edit token di WHM → update IP list. Atau kalau lupa IP server Nawasara, cek:
```bash
curl ifconfig.me
```
