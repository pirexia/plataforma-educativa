<?php

use App\Support\Http\TrustedProxyList;

// REQ-FAM-UNIT-004, ADR-066 §5.8, issue #484: lectura segura de `TRUSTED_PROXIES`.

test('REQ-FAM-UNIT-004 #484: sin variable rige la subred de la red de Quadlet, nunca un comodín', function (): void {
    expect(TrustedProxyList::parse(null, 'production'))->toBe(['10.89.10.0/24'])
        ->and(TrustedProxyList::parse('  ', 'production'))->toBe(['10.89.10.0/24']);
});

test('REQ-FAM-UNIT-004 #484: admite varias IP y CIDR (IPv4 e IPv6) separadas por comas', function (): void {
    expect(TrustedProxyList::parse('10.0.0.1, 192.168.0.0/16,2001:db8::/32', 'production'))->toBe(['10.0.0.1', '192.168.0.0/16', '2001:db8::/32']);
});

test('REQ-FAM-UNIT-004 #484: el comodín aborta el arranque en producción y staging, pero se admite en local y testing', function (): void {
    foreach (['*', '0.0.0.0/0', '::/0', '10.0.0.1,*'] as $raw) {
        foreach (['production', 'staging'] as $env) {
            expect(fn () => TrustedProxyList::parse($raw, $env))->toThrow(InvalidArgumentException::class);
        }
    }

    expect(TrustedProxyList::parse('*', 'local'))->toBe(['*'])
        ->and(TrustedProxyList::parse('*', 'testing'))->toBe(['*']);
});

test('REQ-FAM-UNIT-004 #484: una entrada que no es IP ni CIDR aborta el arranque', function (): void {
    foreach (['traefik', '10.0.0.0/33', '10.0.0.1/x', '2001:db8::/129'] as $raw) {
        expect(fn () => TrustedProxyList::parse($raw, 'production'))->toThrow(InvalidArgumentException::class);
    }
});
