<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Сервис работает с любыми документами: тип у пользовательского шаблона необязателен. */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // шаблонам без типа — первый тип, иначе столбец не вернуть к NOT NULL
        DB::table('templates')->whereNull('document_type_id')
            ->update(['document_type_id' => DB::table('document_types')->min('id')]);
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable(false)->change();
        });
    }
};
