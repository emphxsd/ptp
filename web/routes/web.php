<?php

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // документы пользователя: загрузка, статусы обработки (каждое действие ищет документ среди своих)
    Route::livewire('documents', 'pages::documents.index')->name('documents.index');

    // доступ к конкретному шаблону проверяет TemplatePolicy внутри страниц
    Route::livewire('templates', 'pages::templates.index')->name('templates.index');
    Route::livewire('templates/create', 'pages::templates.create')->name('templates.create');
    Route::livewire('templates/{template}/edit', 'pages::templates.edit')->name('templates.edit');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
});

require __DIR__.'/settings.php';
