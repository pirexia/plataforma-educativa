<?php

use App\Modules\Prl\Application\LockedTransaction;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

// REQ-PRL-004 (1.14b-d1), docs/modulos/REQ-PRL/operacion.md §12.6b, issue #528: el recuento de `503 prl.errors.busy` y de
// reintentos por interbloqueo sale como línea estructurada de nivel warning, sin datos personales. RN-PRL-16, OPEN-PRL-04.

function lockedFailure(string $sqlstate): QueryException
{
    $pdo = new PDOException('simulado');
    $pdo->errorInfo = [$sqlstate, 0, 'simulado'];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

test('RN-PRL-16 issue #528: cada reintento por interbloqueo y el 503 al agotarlos dejan un warning con códigos y contadores, sin datos personales', function (): void {
    $logger = Mockery::mock();
    Log::shouldReceive('channel')->andReturn($logger);
    $logger->shouldReceive('warning')->once()->with('prl.lock.retry', ['sqlstate' => '40P01', 'attempt' => 1]);
    $logger->shouldReceive('warning')->once()->with('prl.lock.retry', ['sqlstate' => '40P01', 'attempt' => 2]);
    $logger->shouldReceive('warning')->once()->with('prl.lock.busy', ['reason' => 'retries_exhausted', 'attempts' => 3]);
    $calls = 0;

    try {
        app(LockedTransaction::class)->run(function () use (&$calls): void {
            $calls++;

            throw lockedFailure('40P01');
        });
        $thrown = null;
    } catch (ApiException $e) {
        $thrown = $e;
    }

    expect($thrown?->status)->toBe(503)
        ->and($calls)->toBe(LockedTransaction::MAX_ATTEMPTS);
});

test('RN-PRL-16 issue #528: superar lock_timeout (55P03) deja un warning prl.lock.busy sin reintento', function (): void {
    $logger = Mockery::mock();
    Log::shouldReceive('channel')->andReturn($logger);
    $logger->shouldReceive('warning')->once()->with('prl.lock.busy', ['reason' => 'lock_timeout', 'attempts' => 1]);
    $calls = 0;

    try {
        app(LockedTransaction::class)->run(function () use (&$calls): void {
            $calls++;

            throw lockedFailure('55P03');
        });
        $thrown = null;
    } catch (ApiException $e) {
        $thrown = $e;
    }

    expect($thrown?->status)->toBe(503)
        ->and($calls)->toBe(1);
});

test('RN-PRL-16 issue #528: un interbloqueo que se resuelve al reintentar deja el warning de reintento y no el de 503', function (): void {
    $logger = Mockery::mock();
    Log::shouldReceive('channel')->andReturn($logger);
    $logger->shouldReceive('warning')->once()->with('prl.lock.retry', ['sqlstate' => '40001', 'attempt' => 1]);
    $calls = 0;

    $result = app(LockedTransaction::class)->run(function () use (&$calls): string {
        $calls++;

        if ($calls === 1) {
            throw lockedFailure('40001');
        }

        return 'ok';
    });

    expect($result)->toBe('ok')->and($calls)->toBe(2);
});
