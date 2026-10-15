<?php

/**
 * Reserve the main database seeding entry point for explicit synthetic datasets.
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Avoid silently creating accounts before the authentication milestone. */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed only datasets explicitly introduced by later feature milestones.
     */
    public function run(): void
    {
    }
}
