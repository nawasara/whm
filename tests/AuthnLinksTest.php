<?php

namespace Nawasara\Whm\Tests;

use Nawasara\Whm\Support\AuthnLinks;
use PHPUnit\Framework\TestCase;

class AuthnLinksTest extends TestCase
{
    private const ROW = [
        'provider_id' => 'kisara',
        'username' => 'Raras.Widayanti.74@ponorogo.go.id',
        'link_time' => 1786334565,
        'preferred_username' => '197405091998072001 - Raras Widayanti',
        'provider_protocol' => 'openid_connect',
        'subject_unique_identifier' => '197405091998072001',
    ];

    public function test_reads_the_nested_shape_whm_actually_returns(): void
    {
        $links = AuthnLinks::fromResponse(['metadata' => ['result' => 1], 'data' => ['payload' => [self::ROW]]]);

        $this->assertSame([[
            'mailbox' => 'raras.widayanti.74@ponorogo.go.id',
            'provider_id' => 'kisara',
            'subject' => '197405091998072001',
            'display_name' => '197405091998072001 - Raras Widayanti',
            'linked_at' => 1786334565,
        ]], $links);
    }

    public function test_reads_a_flat_list_too(): void
    {
        $this->assertCount(1, AuthnLinks::fromResponse(['data' => [self::ROW]]));
    }

    public function test_skips_hosting_logins_and_incomplete_rows(): void
    {
        $links = AuthnLinks::fromResponse(['data' => ['payload' => [
            ['username' => 'kominfo'] + self::ROW,                       // cPanel account, not a mailbox
            ['subject_unique_identifier' => ''] + self::ROW,              // no subject
            ['provider_id' => ''] + self::ROW,
            'garbage',
        ]]]);

        $this->assertSame([], $links);
    }
}
