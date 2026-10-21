<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Для связи с сервисом обработки: согласие на отправку фрагментов во внешнюю LLM (на каждый документ),
 * число страниц, текст документа (подсветка найденных значений по позициям start/end) и уверенность OCR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // согласие на отправку фрагментов во внешнюю LLM — только явное (по умолчанию нет)
            $table->boolean('use_llm')->default(false)->after('has_text_layer');
            $table->unsignedInteger('pages')->nullable()->after('use_llm');
        });

        Schema::table('extraction_results', function (Blueprint $table) {
            $table->longText('text')->nullable()->after('ocr_pages');
            $table->float('ocr_confidence')->nullable()->after('ocr_pages')->comment('Средняя уверенность OCR, 0–100');
        });
    }

    public function down(): void
    {
        Schema::table('extraction_results', function (Blueprint $table) {
            $table->dropColumn(['text', 'ocr_confidence']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['use_llm', 'pages']);
        });
    }
};
