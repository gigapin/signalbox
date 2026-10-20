<?php

/** Seed the explicitly synthetic identity dataset for local development and automated tests. */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Delegate to the environment-guarded, repeatable identity fixture seeder. */
class DatabaseSeeder extends Seeder
{
    /** Seed local/testing fixtures; IdentitySeeder refuses all other environments. */
    public function run(): void
    {
        $this->call(IdentitySeeder::class);
    }
}
