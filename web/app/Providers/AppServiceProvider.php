<?php

namespace App\Providers;

use App\Services\Processor\ProcessorClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessorClient::class, function (): ProcessorClient {
            $url = (string) config('services.processor.url');
            $token = config('services.processor.token') ?: null;
            if (! preg_match('#^https?://[^/?\#]+#i', $url)) {
                throw new RuntimeException('PROCESSOR_URL должен быть адресом http:// или https://.');
            }
            // без токена сервис обработки доступен любому, кто до него достучится
            if ($token === null && $this->app->isProduction()) {
                throw new RuntimeException('В продакшене нужен PROCESSOR_API_TOKEN (тот же, что в processor/.env).');
            }

            return new ProcessorClient(
                $url, $token, (int) config('services.processor.timeout'), (int) config('services.processor.extract_timeout'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $proxies = trim((string) config('app.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
        }

        // временная загрузка Livewire по умолчанию ограничена 12 МБ — поднимаем до предела документов
        config(['livewire.temporary_file_upload.rules' => [
            'required', 'file', 'max:'.((int) config('documents.max_file_mb') * 1024),
        ]]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
