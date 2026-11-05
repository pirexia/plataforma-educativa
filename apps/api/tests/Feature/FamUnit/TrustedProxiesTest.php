<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// REQ-FAM-UNIT-004, ADR-066 §5.8, REQ-PRIV-003, issue #484, INV-015: `$request->ip()` (que registra
// `image_consent_decisions.ip_address`) toma `X-Forwarded-For` solo si la petición llega de un proxy de confianza
// (`TRUSTED_PROXIES`, por defecto la red de Quadlet `10.89.10.0/24`); de cualquier otro origen se ignora.

beforeEach(function (): void {
    Route::get('/__ip-probe', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'scheme' => $request->getScheme(),
    ]));
});

test('REQ-FAM-UNIT-004 #484: desde un proxy de confianza (Traefik en la red de Quadlet) la IP del cliente sale de X-Forwarded-For', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.89.10.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/__ip-probe');

    expect($response->json('ip'))->toBe('203.0.113.9');
});

test('REQ-FAM-UNIT-004 #484: desde cualquier otro origen X-Forwarded-For se ignora y la IP es la de la conexión (no se puede falsear)', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/__ip-probe');

    expect($response->json('ip'))->toBe('198.51.100.20');
});

test('REQ-FAM-UNIT-004 #484: con una cadena de proxies solo se salta la parte de confianza, y el cliente no puede anteponer una IP falsa', function (): void {
    // El cliente (203.0.113.9) envía un X-Forwarded-For con una IP inventada; Traefik añade la que ve.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.89.10.7'])
        ->withHeaders(['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'])
        ->getJson('/__ip-probe');

    expect($response->json('ip'))->toBe('203.0.113.9');
});

test('REQ-FAM-UNIT-004 #484: X-Forwarded-Host y X-Forwarded-Proto no se aceptan (el tenant se resuelve por Host, ADR-014)', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.89.10.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Host' => 'otro-centro.example.com', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/__ip-probe');

    expect($response->json('host'))->not->toBe('otro-centro.example.com')
        ->and($response->json('scheme'))->toBe('http');
});
