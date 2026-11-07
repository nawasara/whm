<?php

namespace Nawasara\Whm\Tests;

use Nawasara\Whm\Jobs\Email\SyncWhmEmailsJob;
use PHPUnit\Framework\TestCase;

class EmailDiskAttrsTest extends TestCase
{
    public function test_light_sync_row_without_disk_fields_leaves_quota_untouched(): void
    {
        // list_pops: no quota/disk fields at all → nothing to write, nothing erased.
        $this->assertSame([], SyncWhmEmailsJob::diskAttrs(['email' => 'a@ponorogo.go.id', 'suspended_login' => 0]));
    }

    public function test_disk_sync_row_sets_quota_and_usage_in_mb(): void
    {
        $out = SyncWhmEmailsJob::diskAttrs(['email' => 'a@x.id', '_diskquota' => 262144000, '_diskused' => 1660928]);
        $this->assertSame(250, $out['quota_mb']);
        $this->assertSame(2.0, $out['disk_used_mb']);
    }

    public function test_unlimited_is_null_only_when_whm_says_so(): void
    {
        $out = SyncWhmEmailsJob::diskAttrs(['email' => 'a@x.id', 'diskquota' => 'unlimited', 'diskused' => '12']);
        $this->assertArrayHasKey('quota_mb', $out);
        $this->assertNull($out['quota_mb']);
        $this->assertSame(12.0, $out['disk_used_mb']);
    }
}
