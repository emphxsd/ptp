<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    private const LOGIN_ATTEMPTS_PER_IP = 20;

    private const REGISTRATIONS_PER_IP = 10;

    private const RESET_LINKS_PER_IP = 5;

    /** Хеш случайной строки: проверка пароля идёт и для несуществующего e-mail (одинаковое время ответа). */
    private const DUMMY_PASSWORD_HASH = '$2y$12$nTCVZdLmDbtPP1EfHR2ud.G0FL.ZTG605M0/8nD0ZVozhcfuaHeHS';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::authenticateUsing(fn (Request $request) => $this->authenticate($request));
    }

    /** Вход по e-mail и паролю; заблокированный пользователь получает понятное сообщение. */
    private function authenticate(Request $request): ?User
    {
        $password = (string) $request->input('password');
        $user = User::where('email', (string) $request->input(Fortify::username()))->first();
        if ($user === null) {
            Hash::check($password, self::DUMMY_PASSWORD_HASH);

            return null;
        }
        if (! Hash::check($password, $user->password)) {
            return null;
        }
        if (! $user->is_active) {
            AuditLog::record('login_blocked', $user, ['ip' => $request->ip()]);

            throw ValidationException::withMessages([Fortify::username() => __('auth.blocked')]);
        }

        return $user;
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // по связке e-mail + IP и отдельно по IP: перебор разных e-mail тоже ограничен
            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_IP)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(self::REGISTRATIONS_PER_IP)->by($request->ip()));
        RateLimiter::for(
            'password-reset-link',
            fn (Request $request) => Limit::perHour(self::RESET_LINKS_PER_IP)->by($request->ip()),
        );
    }
}
