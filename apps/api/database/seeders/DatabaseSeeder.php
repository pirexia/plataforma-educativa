<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // ADR-034 §1: User es de tenant (necesita TenantContext::enter()
        // activo) y person_id es NOT NULL — UserFactory ya crea la persona
        // de paso. Semilla real de desarrollo pendiente de REQ-SEED.
        User::factory()->create([
            'email' => 'test@example.com',
        ]);
    }
}
