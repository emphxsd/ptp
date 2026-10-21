<?php

namespace App\Services\Processor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-клиент сервиса обработки (../processor). Ответы сервиса — конверт {success, data, error}.
 * Сетевые ошибки, коды не 2xx и ответы не по формату — ProcessorUnavailableException.
 */
class ProcessorClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeoutSeconds,
    ) {}

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
            throw new ProcessorUnavailableException('Сервис обработки вернул некорректный ответ (HTTP '.$response->status().').');
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
        $request = Http::baseUrl($this->baseUrl)->timeout($this->timeoutSeconds)->acceptJson();

        return $this->token ? $request->withToken($this->token) : $request;
    }
}
