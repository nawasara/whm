<?php

namespace Nawasara\Whm\Services;

use Nawasara\Whm\Exceptions\MailboxNotFoundException;
use Nawasara\Whm\Exceptions\MailboxSuspendedException;
use Nawasara\Whm\Exceptions\WebmailSessionException;

/**
 * Forge one-shot webmail/cPanel session URL via WHM `create_user_session`
 * API. URL berlaku ~5 menit (default WHM), single-use.
 *
 * Pemakaian:
 *   $svc = app(WebmailSessionService::class);
 *   $result = $svc->createWebmailUrl('bambang@ponorogo.go.id');
 *   return redirect($result['url']);
 *
 * Service ini sengaja tipis — yang melakukan resolve "siapa user Nawasara
 * → mailbox mana" ada di EmailLinkResolver (nawasara/core). Service ini
 * hanya orchestrator antara request app-side dan WHM API.
 *
 * Multi-instance aware: pakai `forInstance($name)` untuk pilih WHM mana
 * (kalau ada >1 server). Default ambil instance dengan role `mail` dari
 * config Vault.
 */
class WebmailSessionService
{
    public function __construct(protected WhmClient $client)
    {
    }

    /**
     * Resolve mailbox status + forge session URL untuk `webmaild` service.
     *
     * @return array{url:string, expires_in:int, instance:?string, service:string}
     *
     * @throws MailboxNotFoundException  saat mailbox tidak terdaftar di WHM
     * @throws MailboxSuspendedException saat parent cPanel atau mailbox suspended
     * @throws WebmailSessionException   saat WHM API error / config invalid
     */
    public function createWebmailUrl(string $emailAccount, ?string $instance = null): array
    {
        return $this->createSession($emailAccount, 'webmaild', $instance);
    }

    /**
     * General session creator — bisa untuk service lain (cpaneld, whostmgrd).
     * Webmail launcher cukup pakai createWebmailUrl().
     *
     * Parameter $user shape tergantung $service:
     *   - webmaild  : full email address `local@domain` (validated as mailbox)
     *   - cpaneld   : cPanel account username, tanpa @ (validated as account)
     *   - whostmgrd : WHM admin username, biasanya `root` (no validation)
     *
     * Validasi pre-flight terbatas ke webmail + cpanel — yang punya snapshot
     * di Nawasara DB. Service lain langsung pass-through ke WHM API; kalau
     * input invalid, WHM error response yang akan muncul (di-classify nanti
     * di controller/livewire).
     */
    public function createSession(string $user, string $service = 'webmaild', ?string $instance = null): array
    {
        $client = $this->resolveClient($instance, $service);

        if (! $client->isConfigured()) {
            throw new WebmailSessionException('WHM credential belum lengkap untuk instance "'.($instance ?? 'default').'".');
        }

        // Validasi pre-flight by service kind. webmaild butuh email validation
        // + mailbox-exists check. cpaneld butuh account-exists check (no @).
        // Service lain skip — assume caller sudah validate.
        if ($service === 'webmaild') {
            $this->ensureMailboxAccessible($client, $user);
        } elseif ($service === 'cpaneld') {
            $this->ensureCpanelAccountAccessible($client, $user);
        }

        // WHM endpoint: GET /json-api/create_user_session?api.version=1&user={user}&service=...&locale=en
        // Untuk webmail: user = full email address.
        // Untuk cpanel:  user = cPanel account username.
        try {
            $response = $client->rawJsonApi('create_user_session', [
                'api.version' => 1,
                'user' => $user,
                'service' => $service,
                'locale' => 'en',
            ]);
        } catch (\Throwable $e) {
            throw new WebmailSessionException('Gagal panggil WHM API: '.$e->getMessage(), 0, $e);
        }

        $url = $response['data']['url'] ?? null;
        $expires = (int) ($response['data']['expires'] ?? 300);

        if (! $url) {
            $reason = $response['metadata']['reason'] ?? 'unknown';
            throw new WebmailSessionException('WHM tidak mengembalikan URL session: '.$reason);
        }

        $url = $this->rewritePublicHost($url, $client->instance(), $service);

        return [
            'url' => $url,
            'expires_in' => $expires,
            'instance' => $client->instance(),
            'service' => $service,
        ];
    }

    /**
     * Rewrite host bagian dari session URL ke public hostname per service.
     * Path + query string + cpsess token tetap intact — cuma scheme + host
     * + port yang ditukar.
     *
     * Vault key per service:
     *   - webmaild  → `webmail_host`  (mis. https://gentapraja.ponorogo.go.id:2096)
     *   - cpaneld   → `cpanel_host`   (mis. https://cpserv.ponorogo.go.id:2083)
     *   - whostmgrd → `whm_host`      (mis. https://cpserv.ponorogo.go.id:2087)
     *
     * Use case: WHM API balikin URL session pakai IP server atau hostname
     * internal (mis. `103.109.206.30:2083`). Kalau user akses URL itu
     * langsung, browser nge-blok karena SSL cert mismatch (cert issued
     * untuk hostname, bukan IP) atau hostname tidak resolvable dari LAN
     * client.
     *
     * Kalau cookie domain WHM scoped ke origin asli (IP), login tetap valid
     * karena Set-Cookie pertama-tama yang dikirim browser dari origin baru
     * akan match (cPanel issue cookie domain wide enough). URL yang user
     * lihat di address bar akhirnya pakai hostname public.
     *
     * Kalau Vault key untuk service ini kosong, return URL apa adanya —
     * konfigurasi opt-in per service per instance.
     */
    protected function rewritePublicHost(string $url, ?string $instance, string $service = 'webmaild'): string
    {
        $vaultKey = match ($service) {
            'cpaneld' => 'cpanel_host',
            'whostmgrd' => 'whm_host',
            'webmaild' => 'webmail_host',
            default => 'webmail_host',
        };

        $publicHost = (string) \Nawasara\Vault\Facades\Vault::get('whm', $vaultKey, $instance);
        if ($publicHost === '') {
            return $url;
        }

        $parsed = parse_url($url);
        $publicParsed = parse_url($publicHost);

        if (! $parsed || ! $publicParsed || empty($publicParsed['host'])) {
            // Kalau Vault value tidak parseable sebagai URL, jangan rewrite
            // (defensif — hindari output URL invalid).
            return $url;
        }

        $scheme = $publicParsed['scheme'] ?? ($parsed['scheme'] ?? 'https');
        $host = $publicParsed['host'];
        $port = $publicParsed['port'] ?? ($parsed['port'] ?? null);
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        $portPart = $port ? ":{$port}" : '';
        return "{$scheme}://{$host}{$portPart}{$path}{$query}{$fragment}";
    }

    /**
     * Pilih instance WHM berdasarkan service kind:
     *   - webmaild → role `mail`     (mail server, mis. WHM-Ryder)
     *   - cpaneld  → role `hosting`  (hosting server, mis. WHM-30)
     *   - lainnya  → role `mail` (default fallback yang punya backwards compat
     *                              ke pemanggil lama yang skip $service param)
     *
     * Kalau caller specify $instance eksplisit, pakai itu langsung tanpa
     * lookup role. Itu kasus admin yang tahu mau target instance mana.
     *
     * Kalau role tidak ada instance match (mis. user belum config WHM
     * hosting), fall through ke default client tanpa instance — itu akan
     * trigger isConfigured() check di caller dengan pesan error yang clear
     * ("WHM credential belum lengkap").
     */
    protected function resolveClient(?string $instance, string $service = 'webmaild'): WhmClient
    {
        if ($instance) {
            return $this->client->forInstance($instance);
        }

        $role = match ($service) {
            'cpaneld' => 'hosting',
            'webmaild' => 'mail',
            default => 'mail',
        };

        $default = $this->client->defaultInstanceForRole($role);
        return $default ? $this->client->forInstance($default) : $this->client;
    }

    /**
     * Validasi: mailbox terdaftar di WHM, dan baik mailbox maupun parent
     * cPanel account-nya tidak suspended. Jangan trust cache app-side
     * (nawasara_email_accounts) — cek langsung WHM cache (5-menit TTL)
     * yang lebih dekat ke source of truth.
     */
    protected function ensureMailboxAccessible(WhmClient $client, string $emailAccount): void
    {
        [$localPart, $domain] = $this->splitEmail($emailAccount);

        $accounts = $client->getCachedEmailAccounts();
        $match = collect($accounts)->first(function ($acc) use ($localPart, $domain) {
            // WHM list_pops shape: ['email' => 'user@domain', ...] atau ['user' => 'user', 'domain' => 'domain']
            $accEmail = $acc['email'] ?? (($acc['user'] ?? null).'@'.($acc['domain'] ?? ''));
            return strcasecmp($accEmail, "{$localPart}@{$domain}") === 0;
        });

        if (! $match) {
            throw new MailboxNotFoundException("Mailbox `{$emailAccount}` tidak terdaftar di WHM.");
        }

        // Mailbox-level suspension flags (cPanel exposes berbeda nama tergantung versi)
        $mailboxSuspended = ($match['suspended_login'] ?? false)
            || ($match['suspended_outgoing'] ?? false)
            || ($match['suspended'] ?? false);

        if ($mailboxSuspended) {
            throw new MailboxSuspendedException("Mailbox `{$emailAccount}` sedang suspended (mailbox-level).");
        }

        // Parent cPanel account suspension — cek listAccounts cache. Kalau
        // parent suspended, semua mailbox di bawahnya tidak bisa dipakai
        // walau flag mailbox-nya bersih.
        //
        // WHM listaccts response convention:
        //   - `suspended`: 0|1 (atau "0"/"1" string)
        //   - `suspendreason`: literal "not suspended" saat aktif, alasan
        //     spesifik saat suspended. Field ini SELALU ada isinya, jadi
        //     jangan trust empty-string check — andalkan flag `suspended`.
        $accs = $client->getCachedAccounts();
        $parent = collect($accs)->first(fn ($a) => strcasecmp($a['domain'] ?? '', $domain) === 0);

        if ($parent && (int) ($parent['suspended'] ?? 0) === 1) {
            $reason = (string) ($parent['suspendreason'] ?? 'unknown');
            throw new MailboxSuspendedException("Parent cPanel account untuk domain `{$domain}` sedang suspended ({$reason}).");
        }
    }

    /**
     * Validasi: cPanel account terdaftar di WHM (via listaccts cache) dan
     * tidak suspended. Mirip ensureMailboxAccessible tapi key-nya `username`
     * bukan `email`/domain. Throw MailboxNotFoundException/MailboxSuspendedException
     * supaya controller bisa render pesan yang konsisten.
     *
     * Catatan: nama exception masih `Mailbox*` walaupun konteksnya cPanel
     * account — exception class itu sudah di-share dengan webmail flow.
     * Renaming jadi `Account*` butuh refactor downstream (controller match
     * by class), low-priority untuk sekarang.
     */
    protected function ensureCpanelAccountAccessible(WhmClient $client, string $cpanelUser): void
    {
        $cpanelUser = trim($cpanelUser);
        if ($cpanelUser === '') {
            throw new WebmailSessionException('cPanel username kosong.');
        }

        $accs = $client->getCachedAccounts();
        $match = collect($accs)->first(fn ($a) => strcasecmp($a['user'] ?? '', $cpanelUser) === 0);

        if (! $match) {
            throw new MailboxNotFoundException("Akun cPanel `{$cpanelUser}` tidak terdaftar di WHM.");
        }

        if ((int) ($match['suspended'] ?? 0) === 1) {
            $reason = (string) ($match['suspendreason'] ?? 'unknown');
            throw new MailboxSuspendedException("Akun cPanel `{$cpanelUser}` sedang suspended ({$reason}).");
        }
    }

    /**
     * @return array{0:string, 1:string} [localPart, domain]
     */
    protected function splitEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            throw new WebmailSessionException("Format email tidak valid: `{$email}`");
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }
}
