<?php

namespace Nawasara\Whm\Livewire\Concerns;

use Nawasara\Whm\Services\WhmClient;

/**
 * Apply this trait to Livewire components that need a role-scoped server selector.
 *
 * The component must define a `serverRole()` method returning 'hosting' or 'mail'.
 * Optional: override `defaultInstance()` for custom default logic.
 */
trait HasServerRole
{
    /**
     * Filter instances by this component's role.
     */
    protected function rolledInstances(WhmClient $whm): array
    {
        return $whm->instancesByRole($this->serverRole());
    }

    /**
     * Resolve the default instance for the component's role.
     */
    protected function defaultInstance(WhmClient $whm): ?string
    {
        return $whm->defaultInstanceForRole($this->serverRole());
    }

    /**
     * The role required by this page.
     * Override in component: return 'hosting' or 'mail'.
     */
    abstract protected function serverRole(): string;
}
