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
     */
    public function createSession(string $emailAccount, string $service = 'webmaild', ?string $instance = null): array
    {
        $client = $this->resolveClient($instance);

        if (! $client->isConfigured()) {
            throw new WebmailSessionException('WHM credential belum lengkap untuk instance "'.($instance ?? 'default').'".');
        }

        $this->ensureMailboxAccessible($client, $emailAccount);

        // WHM endpoint: GET /json-api/create_user_session?api.version=1&user={email}&service=webmaild&locale=en
        // Catatan: untuk login webmail, parameter `user` HARUS email lengkap (user@domain),
        // bukan username cPanel. Service `webmaild` tahu dispatch ke mailbox berdasarkan email.
        try {
            $response = $client->rawJsonApi('create_user_session', [
                'api.version' => 1,
                'user' => $emailAccount,
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

        return [
            'url' => $url,
            'expires_in' => $expires,
            'instance' => $client->instance(),
            'service' => $service,
        ];
    }

    /**
     * Pilih instance WHM. Kalau caller tidak specify, ambil instance pertama
     * yang punya role `mail` (atau `both`). Itu match konvensi WHM-Ryder
     * yang dedicated mail server.
     */
    protected function resolveClient(?string $instance): WhmClient
    {
        if ($instance) {
            return $this->client->forInstance($instance);
        }

        $default = $this->client->defaultInstanceForRole('mail');
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
