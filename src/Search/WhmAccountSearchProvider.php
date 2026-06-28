<?php

namespace Nawasara\Whm\Search;

use Nawasara\Search\Contracts\SearchProvider;
use Nawasara\Whm\Models\WhmAccount;

class WhmAccountSearchProvider implements SearchProvider
{
    public function key(): string
    {
        return 'whm-account';
    }

    public function label(): string
    {
        return 'Hosting (WHM)';
    }

    public function permission(): ?string
    {
        return 'whm.account.view';
    }

    public function search(string $term, int $limit): array
    {
        return WhmAccount::query()
            ->search($term)
            ->orderBy('domain')
            ->limit($limit)
            ->get(['id', 'username', 'domain'])
            ->map(fn (WhmAccount $a) => [
                'label' => $a->domain,
                'sublabel' => $a->username,
                'url' => url('nawasara-whm/accounts?search='.urlencode($term)),
            ])
            ->all();
    }
}
