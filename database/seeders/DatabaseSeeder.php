<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // The settings, added when missing. The themes of Erugo are no longer seeded: no page reads
    // them, and their file only worked on SQLite; those an instance already holds stay.
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
        ]);
    }
}
