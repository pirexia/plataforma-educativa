<?php

namespace App\Support\Files;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADR-068 §5.3, `INV-001`: escritura, firma y borrado de objetos del *bucket* privado, bajo
 * `tenants/<tenant public_id>/<área>/<ULID>.<ext>`.
 *
 * **El centro lo toma de `TenantContext`, nunca de un parámetro**: ningún llamador puede escribir en el prefijo de otro
 * centro. `temporaryUrl()` y `delete()` solo aceptan claves que cuelguen del prefijo del centro en curso y lanzan
 * `ForeignObjectKeyException` en cualquier otro caso. La clave es un ULID: nunca contiene datos de la persona.
 *
 * Disco: `files.disk` (por defecto `filesystems.default`), que debe ser S3 compatible en `production` y `staging` (`disk()`). Sin memoización del `public_id` del centro: el contexto cambia
 * entre peticiones, trabajos y tests.
 */
final class PrivateObjectStore
{
    /** Área: segmentos en minúscula separados por `/` (`fam-unit/pickup-photos`). */
    private const AREA_PATTERN = '#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Escribe `$contents` y devuelve la clave del objeto.
     */
    public function put(string $area, string $contents, string $extension): string
    {
        if (preg_match(self::AREA_PATTERN, $area) !== 1 || preg_match('/^[a-z0-9]{2,5}$/', $extension) !== 1) {
            throw new InvalidArgumentException('Área o extensión de objeto no válidas.');
        }

        $key = sprintf('%s%s/%s.%s', $this->prefix(), $area, (string) Str::ulid(), $extension);

        if (! $this->disk()->put($key, $contents)) {
            throw new RuntimeException('No se pudo escribir el objeto en el almacenamiento.');
        }

        return $key;
    }

    /** URL firmada de caducidad corta de un objeto del centro en curso. Se genera en cada respuesta y no se cachea. */
    public function temporaryUrl(string $key, CarbonInterface $expiresAt): string
    {
        $this->assertOwn($key);

        return $this->disk()->temporaryUrl($key, $expiresAt);
    }

    public function exists(string $key): bool
    {
        $this->assertOwn($key);

        return $this->disk()->exists($key);
    }

    public function get(string $key): ?string
    {
        $this->assertOwn($key);

        $contents = $this->disk()->get($key);

        return is_string($contents) ? $contents : null;
    }

    /** Instante (marca de tiempo Unix) de la última modificación de un objeto del centro en curso. */
    public function lastModified(string $key): int
    {
        $this->assertOwn($key);

        return $this->disk()->lastModified($key);
    }

    public function delete(string $key): void
    {
        $this->assertOwn($key);

        $this->disk()->delete($key);
    }

    /**
     * Claves de un área del centro en curso (para el barrido de huérfanos).
     *
     * @return list<string>
     */
    public function keysIn(string $area): array
    {
        if (preg_match(self::AREA_PATTERN, $area) !== 1) {
            throw new InvalidArgumentException('Área de objeto no válida.');
        }

        return array_values($this->disk()->files($this->prefix().$area));
    }

    /** `tenants/<public_id>/` del centro en curso. */
    private function prefix(): string
    {
        $publicId = Tenant::query()->whereKey($this->tenant->tenantId())->value('public_id');

        if (! is_string($publicId) || $publicId === '') {
            throw new RuntimeException('No hay un centro en curso para el almacenamiento privado.');
        }

        return "tenants/{$publicId}/";
    }

    private function assertOwn(string $key): void
    {
        $prefix = $this->prefix();

        if (! str_starts_with($key, $prefix) || str_contains($key, '..') || strlen($key) === strlen($prefix)) {
            throw new ForeignObjectKeyException('La clave de objeto no pertenece al centro en curso.');
        }
    }

    /**
     * El disco privado (`files.disk`, por defecto `filesystems.default`). **Falla en cerrado con un disco `local` en
     * `production` y `staging`** (ajuste aprobado por el usuario el 2026-11-02; antes, en todo entorno salvo `testing`): las
     * fotografías de recogida son datos de menores y de terceros y el almacenamiento de la aplicación es S3 compatible
     * (`ADR-013`); un disco local escribiría en el sistema de ficheros del contenedor (aplicación *stateless*, `CLAUDE.md §9`)
     * y sus «URL firmadas» no serían de un *bucket* privado. Con `APP_ENV=local` o `testing` el disco local es válido, para que
     * el perfil reducido de desarrollo (sin MinIO, `ADR-030`) tenga fotografías y activos de marca. Un disco S3 vale siempre.
     */
    private function disk(): Filesystem
    {
        $configured = config('files.disk');
        $name = is_string($configured) && $configured !== '' ? $configured : (string) config('filesystems.default');

        if (config("filesystems.disks.{$name}.driver") === 'local' && app()->environment(['production', 'staging'])) {
            throw new RuntimeException('El almacenamiento privado no puede ser un disco local: configura FILESYSTEM_DISK=s3 y las variables AWS_* (en desarrollo, MinIO con el perfil full).');
        }

        return Storage::disk($name);
    }
}
