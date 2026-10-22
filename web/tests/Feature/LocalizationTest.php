<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

/** Интерфейс на русском: страницы стартового набора, сообщения проверки, письма, постраничный вывод. */
class LocalizationTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    public function test_application_locale_is_russian(): void
    {
        $this->assertSame('ru', app()->getLocale());
        $this->get(route('login'))->assertSee('<html lang="ru"', false);
    }

    public function test_every_interface_string_has_russian_translation(): void
    {
        $translations = json_decode((string) file_get_contents(lang_path('ru.json')), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];
        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)+)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $key = stripslashes($key);
                // русские строки и ключи файлов (auth.failed) переводить не нужно
                if (! preg_match('/[А-Яа-яЁё]/u', $key) && ! preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) && ! isset($translations[$key])) {
                    $missing[] = $key.' — '.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $missing, 'Нет перевода в lang/ru.json');
    }

    public function test_russian_validation_messages_cover_every_rule_with_same_placeholders(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $russian = require lang_path('ru/validation.php');
        $placeholders = fn (string $message): array => array_values(array_unique(preg_match_all('/:[a-z_]+/', $message, $m) ? $m[0] : []));
        // в сообщениях о файлах и пароле предмет назван словом («Файл…», «Пароль…»), поэтому :attribute там можно опустить
        $subjectNamed = ['current_password', 'extensions', 'image', 'mimes', 'mimetypes', 'password', 'uploaded'];

        foreach ($english as $rule => $message) {
            if (in_array($rule, ['custom', 'attributes'], true)) {
                continue;
            }
            $this->assertArrayHasKey($rule, $russian, "нет перевода правила {$rule}");
            foreach ((array) $message as $variant => $text) {
                $translated = is_array($message) ? ($russian[$rule][$variant] ?? null) : $russian[$rule];
                $this->assertIsString($translated, "нет перевода {$rule}.{$variant}");
                $optional = $variant === 'file' || in_array($rule, $subjectNamed, true) ? [':attribute'] : [];
                $this->assertEqualsCanonicalizing(
                    array_values(array_diff($placeholders($text), $optional)),
                    array_values(array_diff($placeholders($translated), $optional)),
                    "подстановки в {$rule}.{$variant}",
                );
            }
        }
    }

    public function test_guest_pages_are_in_russian(): void
    {
        $this->get('/')->assertOk()->assertSee('Войти')->assertSee('Регистрация')->assertDontSee('Laravel');
        $this->get(route('login'))->assertSee('Вход в систему')->assertSee('Забыли пароль?')->assertDontSee('Log in');
        $this->get(route('register'))->assertSee('Регистрация')->assertSee('Создать учётную запись')->assertDontSee('Create account');
        $this->get(route('password.request'))->assertSee('Восстановление пароля');
    }

    public function test_validation_messages_are_in_russian(): void
    {
        $this->post(route('register.store'), ['email' => 'не-почта', 'password' => '1', 'password_confirmation' => '2'])
            ->assertSessionHasErrors([
                'name' => 'Поле «ФИО» обязательно для заполнения.',
                'email' => 'Поле «E-mail» должно быть действительным адресом электронной почты.',
                'password' => 'Поле «Пароль» не совпадает с подтверждением.',
            ]);
    }

    public function test_failed_login_message_is_in_russian(): void
    {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Неверный e-mail или пароль.']);
    }

    public function test_menu_and_settings_are_in_russian(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('Главная')->assertSee('Настройки')->assertSee('Выйти')
            ->assertDontSee('Repository')->assertDontSee('Documentation')->assertDontSee('Platform');
        $this->actingAs($user)->get(route('profile.edit'))->assertSee('Профиль')->assertSee('Удалить учётную запись');
        $this->actingAs($user)->get(route('appearance.edit'))->assertSee('Оформление')->assertSee('Тёмная');
    }

    public function test_dashboard_summarises_users_work(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create();
        $this->processedInvoice($user);
        $this->processedInvoice($user, attributes: ['needs_review' => true]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('Документов обработано')
            ->assertSee('Требуют проверки')
            ->assertSee(route('documents.index', ['review' => 1]), false)
            ->assertSee(route('templates.index'), false);
    }

    public function test_pagination_is_in_russian(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(30)->create();

        $this->actingAs($admin)->get(route('admin.index'))->assertSee('Показано')->assertSee('из');
    }

    public function test_upload_errors_are_in_russian(): void
    {
        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('template_id', Template::where('is_system', true)->value('id'))
            ->set('files', [UploadedFile::fake()->create('большой.pdf', config('documents.max_file_mb') * 1024 + 1, 'application/pdf')])
            ->assertHasErrors('files.0')
            ->assertSee('не должен превышать');
    }

    public function test_file_sizes_are_shown_in_russian_units(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create();
        $this->processedInvoice($user)->forceFill(['size_bytes' => 395_776])->save();

        $this->actingAs($user)->get(route('documents.index'))->assertSee("386,5\u{00A0}КБ");
    }

    public function test_password_reset_email_is_in_russian(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Сброс пароля' && $mail->actionText === 'Сбросить пароль';
        });
    }
}
