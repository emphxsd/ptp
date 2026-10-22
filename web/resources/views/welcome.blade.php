<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => null])
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center bg-white p-6 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <main class="flex w-full max-w-2xl flex-col gap-8">
            <div class="flex items-center gap-3">
                <x-app-logo />
            </div>

            <div class="flex flex-col gap-3">
                <flux:heading size="xl" level="1">Автоматическое извлечение данных из документов</flux:heading>
                <flux:text>
                    Загрузите счёт, акт, договор или резюме — в PDF, DOCX или скане — и выберите шаблон.
                    Сервис найдёт нужные реквизиты правилами и NER, а пропущенные дополнит с помощью языковой модели.
                    Сомнительные значения помечаются «требует проверки»: их можно сверить с подсвеченным текстом документа и исправить.
                </flux:text>
            </div>

            <ul class="grid gap-3 text-sm sm:grid-cols-3">
                <li class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">Свои шаблоны полей или готовые для четырёх типов документов</li>
                <li class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">Подсветка найденных значений и ручное исправление</li>
                <li class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">Выгрузка результатов в Excel (CSV) и JSON</li>
            </ul>

            <div class="flex flex-wrap gap-3">
                @auth
                    <flux:button variant="primary" :href="route('dashboard')">Перейти к работе</flux:button>
                @else
                    <flux:button variant="primary" :href="route('login')">Войти</flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')">Регистрация</flux:button>
                    @endif
                @endauth
            </div>
        </main>

        @fluxScripts
    </body>
</html>
