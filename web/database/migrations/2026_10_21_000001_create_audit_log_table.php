<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            // запись журнала переживает удаление пользователя
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
