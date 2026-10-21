<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роли создаются раньше пользователей: у каждого пользователя обязательная роль (users.role_id).
 * Сами роли — справочник, поэтому заполняются здесь же, а не сидером.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('roles')->insert([
            [
                'name' => 'user',
                'description' => 'Шаблоны извлечения, загрузка документов, проверка и исправление результатов, экспорт, история своих документов',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'admin',
                'description' => 'Всё, что может пользователь, а также управление пользователями и ролями, системные шаблоны и типы документов, журнал действий, параметры обработки',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
