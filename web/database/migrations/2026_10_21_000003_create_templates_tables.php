<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            // NULL — системный шаблон администратора, доступный всем
            $table->foreignId('user_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('label', 150);
            $table->string('data_type', 30);
            $table->boolean('is_required')->default(false);
            // слова-якоря и правила проверки — как в шаблоне сервиса обработки
            $table->jsonb('keywords')->nullable();
            $table->text('regex_pattern')->nullable();
            $table->string('ner_label', 30)->nullable();
            $table->text('llm_hint')->nullable();
            $table->jsonb('validation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_fields');
        Schema::dropIfExists('templates');
    }
};
