<?php

namespace App\Support\Tenancy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADR-033 §9: cada tenant escribe bajo tenants/{public_id}/ dentro del
 * disco base, resuelto aquí — nunca componiendo la ruta a mano en código
 * de negocio. public_id, no el id interno (ADR-029: el id nunca sale de
 * la aplicación, tampoco como segmento de una ruta de almacenamiento).
 *
 * 'root' es la forma nativa de Laravel/Flysystem de prefijar un disco;
 * funciona igual para el driver local y para s3 (MinIO en desarrollo).
 */
final class TenantStorage
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function disk(?string $name = null): Filesystem
    {
        $name ??= (string) config('filesystems.default');
        $config = config("filesystems.disks.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Disco de almacenamiento [{$name}] no configurado.");
        }

        return Storage::build([
            ...$config,
            'root' => rtrim((string) ($config['root'] ?? ''), '/')."/tenants/{$this->tenantPublicId()}",
        ]);
    }

    private function tenantPublicId(): string
    {
        // Fail closed: sin tenant activo, ni siquiera se resuelve el disco.
        $tenantId = $this->context->tenantId();

        $publicId = Tenant::query()->whereKey($tenantId)->value('public_id');

        if (! is_string($publicId)) {
            throw new RuntimeException("El tenant activo (id={$tenantId}) ya no existe.");
        }

        return $publicId;
    }
}
