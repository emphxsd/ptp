<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // удаление шаблона не удаляет историю обработанных документов
            $table->foreignId('template_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('file_format', 10);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->nullable()->index();
            $table->string('storage_key', 255);
            $table->boolean('has_text_layer')->nullable();
            $table->string('status', 20)->default('uploaded')->index();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            // PostgreSQL не индексирует внешние ключи сам; история документов пользователя — по дате
            $table->index(['user_id', 'uploaded_at']);
        });

        Schema::create('extraction_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->boolean('llm_used')->default(false);
            $table->unsignedInteger('processing_ms')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->jsonb('warnings')->nullable();
            $table->unsignedInteger('ocr_pages')->default(0);
            $table->timestamps();
        });

        Schema::create('extracted_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->index()->constrained('extraction_results')->cascadeOnDelete();
            // поле шаблона могут удалить или переименовать — имя сохраняется в результате
            $table->foreignId('field_id')->nullable()->index()->constrained('template_fields')->nullOnDelete();
            $table->string('field_name', 64);
            $table->text('value')->nullable();
            $table->text('original_value')->nullable();
            $table->string('source', 10)->nullable();
            $table->float('confidence')->default(0);
            $table->text('raw')->nullable();
            $table->unsignedInteger('start_offset')->nullable();
            $table->unsignedInteger('end_offset')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->boolean('is_corrected')->default(false);
            $table->jsonb('issues')->nullable();
            $table->jsonb('alternatives')->nullable();
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->index()->constrained('extraction_results')->cascadeOnDelete();
            $table->string('format', 10);
            $table->string('destination', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
        Schema::dropIfExists('extracted_values');
        Schema::dropIfExists('extraction_results');
        Schema::dropIfExists('documents');
    }
};
