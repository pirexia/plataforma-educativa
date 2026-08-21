<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\TenantSettingsReader;

final class EloquentTenantSettingsReader implements TenantSettingsReader
{
    public function __construct(
        private readonly TenantSettingsCache $cache,
    ) {}

    public function defaultLocale(): string
    {
        return $this->cache->get()['default_locale'];
    }

    public function activeLocales(): array
    {
        return $this->cache->get()['active_locales'];
    }

    public function timezone(): string
    {
        return $this->cache->get()['timezone'];
    }

    public function currency(): string
    {
        return $this->cache->get()['currency'];
    }
}
