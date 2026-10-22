<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\DocumentFileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // документы пользователя: загрузка, статусы обработки (каждое действие ищет документ среди своих)
    Route::livewire('documents', 'pages::documents.index')->name('documents.index');
    Route::livewire('documents/{document}', 'pages::documents.show')->name('documents.show');
    Route::get('documents/{document}/file', DocumentFileController::class)->name('documents.file');

    // доступ к конкретному шаблону проверяет TemplatePolicy внутри страниц
    Route::livewire('templates', 'pages::templates.index')->name('templates.index');
    Route::livewire('templates/create', 'pages::templates.create')->name('templates.create');
    Route::livewire('templates/{template}/edit', 'pages::templates.edit')->name('templates.edit');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
});

require __DIR__.'/settings.php';
