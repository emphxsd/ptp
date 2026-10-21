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
        // роли и типы документов создаются миграциями; администратор — командой php artisan app:create-admin
        if (app()->environment('local')) {
            User::factory(5)->create();
        }
    }
}
