<?php

namespace Tests\Unit;

use App\Rules\UserRegex;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Случаи из tests/Fixtures/regex_cases.json; те же проверяет processor/tests/test_web_contract.py через RE2. */
class UserRegexTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function cases(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/regex_cases.json')), true);
    }

    private function passes(string $pattern): bool
    {
        return Validator::make(['regex' => $pattern], ['regex' => [new UserRegex]])->passes();
    }

    public function test_patterns_supported_by_re2_are_accepted(): void
    {
        foreach ($this->cases()['valid'] as $pattern) {
            $this->assertTrue($this->passes($pattern), $pattern);
        }
    }

    public function test_patterns_rejected_by_re2_are_rejected_before_calling_the_service(): void
    {
        foreach ($this->cases()['rejected'] as $pattern) {
            $this->assertFalse($this->passes($pattern), $pattern);
        }
    }

    public function test_too_complex_patterns_are_left_to_the_service(): void
    {
        // предел сложности программы RE2 в PHP не вычислить — такие поля сохраняются только после проверки сервисом
        foreach ($this->cases()['too_complex'] as $pattern) {
            $this->assertTrue($this->passes($pattern), $pattern);
        }
    }

    public function test_too_long_pattern_is_rejected(): void
    {
        $this->assertFalse($this->passes(str_repeat('a', UserRegex::MAX_LENGTH + 1)));
    }
}
