<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Production-safe: only the authorization catalog is seeded. No demo users,
     * tenants or memberships are created, so seeding can never introduce an
     * identity with predictable/default credentials.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);
    }
}
