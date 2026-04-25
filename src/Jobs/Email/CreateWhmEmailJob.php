<?php

namespace Nawasara\Whm\Jobs\Email;

use Nawasara\Whm\Models\WhmEmailAccount;

class CreateWhmEmailJob extends AbstractWhmEmailJob
{
    protected function action(): string
    {
        return 'create';
    }

    /** Skip conflict check for create — kita justru cek "kalau sudah ada". */
    protected function shouldCheckConflict(): bool
    {
        return false;
    }

    protected function execute(): array
    {
        $email = $this->payload['email'];
        $password = $this->payload['password'];
        $quotaMb = $this->payload['quota_mb'] ?? 0; // 0 = unlimited di WHM

        $whm = $this->client();
        $cpanelUser = $whm->defaultCpanelUser();

        if (! $cpanelUser) {
            throw new \RuntimeException('Cannot resolve cPanel user.');
        }

        // Avoid duplicate
        $existing = WhmEmailAccount::where('instance', $this->instance)
            ->where('email', $email)
            ->first();
        if ($existing && $existing->isSynced()) {
            throw new \RuntimeException("Email {$email} sudah ada.");
        }

        $result = $whm->createEmailAccount($cpanelUser, $email, $password, $quotaMb);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['errors'][0] ?? 'WHM rejected create_pop');
        }

        // Insert ke DB local
        [$localPart, $domain] = explode('@', $email, 2);
        $record = WhmEmailAccount::updateOrCreate(
            ['instance' => $this->instance, 'email' => $email],
            [
                'cpanel_user' => $cpanelUser,
                'local_part' => $localPart,
                'domain' => $domain,
                'quota_mb' => $quotaMb ?: null,
                'suspended_login' => false,
                'suspended_incoming' => false,
            ]
        );

        $record->content_hash = $record->computeContentHash();
        $record->markSynced();
        $record->save();

        return ['email' => $email];
    }
}
