<?php

use App\Http\Controllers\DocumentExportController;
use App\Http\Controllers\DocumentFileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // документы пользователя: загрузка, статусы обработки (каждое действие ищет документ среди своих)
    Route::livewire('documents', 'pages::documents.index')->name('documents.index');
    // выгрузка выборки объявлена до documents/{document}, иначе «export» приняли бы за номер документа
    // выгрузка — тяжёлый запрос с записью в БД: не больше 10 в минуту на пользователя
    Route::get('documents/export', [DocumentExportController::class, 'selection'])
        ->middleware('throttle:10,1')->name('documents.export-selection');
    Route::livewire('documents/{document}', 'pages::documents.show')->name('documents.show');
    Route::get('documents/{document}/file', DocumentFileController::class)->name('documents.file');
    Route::get('documents/{document}/export/{format}', [DocumentExportController::class, 'document'])
        ->middleware('throttle:10,1')->name('documents.export');

    // доступ к конкретному шаблону проверяет TemplatePolicy внутри страниц
    Route::livewire('templates', 'pages::templates.index')->name('templates.index');
    Route::livewire('templates/create', 'pages::templates.create')->name('templates.create');
    Route::livewire('templates/{template}/edit', 'pages::templates.edit')->name('templates.edit');
});

// раздел администратора; права проверяются ещё и в каждом действии страниц (запросы Livewire идут мимо этой группы)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/', 'pages::admin.users')->name('index');
    Route::livewire('audit', 'pages::admin.audit')->name('audit');
});

require __DIR__.'/settings.php';
