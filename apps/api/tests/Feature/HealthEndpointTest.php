<?php

// Paso 0.4 del plan: el esqueleto de la API debe exponer un healthcheck.

test('GET /api/health responde ok con versión y timestamp', function (): void {
    $response = $this->getJson('/api/health');

    $response
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'version', 'timestamp']);
});
