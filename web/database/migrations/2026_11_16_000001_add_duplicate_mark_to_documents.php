<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Пометка «возможный дубликат»: ссылка на ранее загруженный документ и причина (тот же файл или тот же счёт). */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // удалили оригинал — пометка снимается сама
            $table->foreignId('duplicate_of_id')->nullable()->after('use_llm')->constrained('documents')->nullOnDelete();
            $table->string('duplicate_reason', 10)->nullable()->after('duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropColumn('duplicate_reason');
        });
    }
};
