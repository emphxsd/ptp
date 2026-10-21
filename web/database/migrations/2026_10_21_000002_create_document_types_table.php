<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // четыре типа документов из цели проекта; администратор может добавить свои
        $types = [
            ['Счёт на оплату', 'Реквизиты поставщика и покупателя, номер, дата, суммы'],
            ['Акт выполненных работ', 'Стороны, номер и дата акта, стоимость работ, подписанты'],
            ['Договор', 'Номер, дата, стороны и их представители, цена, сроки'],
            ['Резюме', 'ФИО кандидата, контакты, желаемая должность'],
        ];
        DB::table('document_types')->insert(array_map(fn (array $type) => [
            'name' => $type[0],
            'description' => $type[1],
            'created_at' => now(),
            'updated_at' => now(),
        ], $types));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
