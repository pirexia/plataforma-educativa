<?php

namespace Database\Factories;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => 'Centro ficticio '.$this->faker->unique()->company(),
            'status' => TenantStatus::Activo,
        ];
    }

    public function suspendido(): static
    {
        return $this->state(['status' => TenantStatus::Suspendido]);
    }
}
