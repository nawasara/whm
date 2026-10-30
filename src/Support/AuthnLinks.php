<?php

namespace Nawasara\Whm\Support;

/**
 * Parses WHM `get_users_authn_linked_accounts`.
 *
 * The rows sit one level deeper than most WHM calls (`data` holds a single
 * list), so both shapes are accepted. Only mailbox links are kept: the
 * username of a webmail link is a full address; a bare cPanel username is a
 * hosting account login, not a mailbox.
 */
final class AuthnLinks
{
    /**
     * @return list<array{mailbox: string, provider_id: string, subject: string, display_name: ?string, linked_at: ?int}>
     */
    public static function fromResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $rows = is_array($data) && count($data) === 1 && is_array(reset($data)) && array_is_list(reset($data))
            ? reset($data)
            : $data;

        $out = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mailbox = strtolower(trim((string) ($row['username'] ?? '')));
            $provider = (string) ($row['provider_id'] ?? '');
            $subject = trim((string) ($row['subject_unique_identifier'] ?? ''));

            if (! str_contains($mailbox, '@') || $provider === '' || $subject === '') {
                continue;
            }

            $display = $row['preferred_username'] ?? null;

            $out[] = [
                'mailbox' => $mailbox,
                'provider_id' => $provider,
                'subject' => $subject,
                'display_name' => is_string($display) && $display !== '' ? mb_substr($display, 0, 255) : null,
                'linked_at' => is_numeric($row['link_time'] ?? null) ? (int) $row['link_time'] : null,
            ];
        }

        return $out;
    }
}
