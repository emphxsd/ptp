<?php

namespace App\Services\Processor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP-клиент сервиса обработки (../processor). Ответы сервиса — конверт {success, data, error}.
 * Сетевые ошибки, коды не 2xx и ответы не по формату — ProcessorUnavailableException.
 */
class ProcessorClient
{
    // ответы, при которых имеет смысл повторить запрос позже: сервис перегружен, перезапускается или за прокси
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeoutSeconds,
        private readonly int $extractTimeoutSeconds,
    ) {}

    /**
     * Обрабатывает документ шаблоном: правила, NER, при разрешении — LLM; текст документа возвращается для подсветки.
     *
     * @param  StreamInterface  $file  поток содержимого файла (клиент закрывает его после отправки)
     * @param  array{name: string, fields: list<array<string, mixed>>}  $template
     *
     * @throws ProcessorRejectedException документ или шаблон отклонён — повтор не поможет
     * @throws ProcessorUnavailableException сервис недоступен или ответил не по формату — можно повторить позже
     */
    public function extract(StreamInterface $file, string $fileName, array $template, bool $useLlm): Extraction
    {
        try {
            $response = $this->request()
                ->timeout($this->extractTimeoutSeconds)
                ->attach('file', $file, $fileName)
                ->post('/extract', [
                    'template' => json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'include_text' => 'true',
                    'use_llm' => $useLlm ? 'true' : 'false',
                    // интерфейс обещал «документ никуда не передаётся» — сервис не отдаст его облачной LLM
                    'llm_local_only' => config('documents.llm_external') ? 'false' : 'true',
                ]);
        } catch (ConnectionException) {
            throw new ProcessorUnavailableException('Сервис обработки недоступен.');
        }

        if ($response->successful()) {
            return Extraction::fromResponse($response->json('data'));
        }

        $code = $response->json('error.code');
        $message = $response->json('error.message');
        if ($response->clientError() && ! in_array($response->status(), self::RETRYABLE_STATUSES, true)
            && is_string($code) && is_string($message) && $response->status() !== 401) {
            throw new ProcessorRejectedException(mb_substr($code, 0, 50), mb_substr($message, 0, 1000));
        }

        // подробности (401 — неверный PROCESSOR_API_TOKEN, 5xx — сбой сервиса) — в лог, пользователю — общее сообщение
        Log::warning('Сервис обработки не обработал документ', ['status' => $response->status(), 'code' => is_string($code) ? $code : null]);
        throw new ProcessorUnavailableException('Сервис обработки временно недоступен.');
    }

    /**
     * Проверяет шаблон схемой сервиса (включая регулярные выражения через RE2) без обработки документа.
     *
     * @param  array{name: string, fields: list<array<string, mixed>>}  $template
     * @return list<array{field: string, message: string}> пустой список — шаблон корректен
     *
     * @throws ProcessorUnavailableException
     */
    public function validateTemplate(array $template): array
    {
        try {
            $response = $this->request()->asForm()->post('/templates/validate', [
                'template' => json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        } catch (ConnectionException) {
            throw new ProcessorUnavailableException('Сервис обработки недоступен.');
        }

        $data = $response->successful() ? $response->json('data') : null;
        if (! is_array($data) || ! is_bool($data['valid'] ?? null) || ! is_array($data['issues'] ?? null)) {
            Log::warning('Сервис обработки не проверил шаблон', ['status' => $response->status()]);
            throw new ProcessorUnavailableException('Сервис обработки недоступен.');
        }

        $issues = [];
        foreach ($data['issues'] as $issue) {
            if (is_array($issue) && is_string($issue['field'] ?? null) && is_string($issue['message'] ?? null)) {
                $issues[] = ['field' => $issue['field'], 'message' => $issue['message']];
            }
        }
        if (! $data['valid'] && $issues === []) {
            $issues[] = ['field' => 'template', 'message' => 'Шаблон отклонён сервисом обработки.'];
        }

        return $issues;
    }

    private function request(): PendingRequest
    {
        // без переходов по перенаправлениям: иначе файл и токен ушли бы по адресу из ответа
        $request = Http::baseUrl($this->baseUrl)->timeout($this->timeoutSeconds)->acceptJson()->withoutRedirecting();

        return $this->token ? $request->withToken($this->token) : $request;
    }
}
