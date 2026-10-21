<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use App\Services\Processor\Extraction;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorRejectedException;
use App\Services\Processor\ProcessorUnavailableException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Throwable;

/**
 * Обработка загруженного документа сервисом (POST /extract) и сохранение результата.
 * Сервис недоступен или перегружен — исключение, очередь повторит попытку; документ отклонён
 * (защищён паролем, повреждён и т. п.) — сразу «Ошибка» с кодом и сообщением сервиса.
 * Сроки согласованы: HTTP-запрос (120 с) < $timeout (180 с) < retry_after очереди (240 с) < $uniqueFor (600 с).
 */
class ProcessDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // сервис отвечает до 30 с, плюс OCR сканов; запас на ожидание в его очереди
    public int $timeout = 180;

    // блокировка уникальности истекает: после аварии обработчика документ можно отправить повторно
    public int $uniqueFor = 600;

    // документ удалили до обработки — задание просто снимается
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Document $document) {}

    /** @return list<int> паузы перед повторными попытками, секунды */
    public function backoff(): array
    {
        return [10, 30];
    }

    // один документ не обрабатывается двумя заданиями сразу (повторная отправка, двойной щелчок)
    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(ProcessorClient $processor): void
    {
        // состояние на момент выполнения, а не постановки в очередь: документ могли удалить или уже обработать
        $document = $this->document->fresh();
        if ($document === null || ! in_array($document->status, [DocumentStatus::Uploaded, DocumentStatus::Processing], true)) {
            return;
        }

        $template = $document->template_id === null ? null : Template::with('fields')->find($document->template_id);
        if ($template === null || $template->fields->isEmpty()) {
            $this->markFailed('TEMPLATE_UNAVAILABLE', 'Шаблон удалён или в нём нет полей. Загрузите документ с другим шаблоном.');

            return;
        }

        $disk = Storage::disk(Document::DISK);
        try {
            $stream = $disk->exists($document->storage_key) ? $disk->readStream($document->storage_key) : null;
        } catch (UnableToReadFile) {
            $stream = null;
        }
        if (! is_resource($stream)) {
            $this->markFailed('FILE_MISSING', 'Файл документа не найден в хранилище.');

            return;
        }

        $document->forceFill(['status' => DocumentStatus::Processing])->save();
        // HTTP-клиент сам закрывает поток после отправки; close() потока PSR-7 безопасен и повторно,
        // а без него поток остался бы открытым, если запрос не ушёл (обработчик очереди живёт долго)
        $body = Utils::streamFor($stream);
        try {
            // имя файла пользователя (может содержать ФИО) в сервис не передаётся
            $extraction = $processor->extract(
                $body, 'document.'.$document->file_format, $template->toProcessorPayload(), $document->use_llm,
            );
        } catch (ProcessorRejectedException $rejected) {
            $this->markFailed($rejected->errorCode, $rejected->getMessage());

            return;
        } finally {
            $body->close();
        }

        $this->saveResult($template, $extraction);
    }

    /** Попытки исчерпаны или непредвиденная ошибка: пользователь видит понятную причину без технических деталей. */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof ProcessorUnavailableException) {
            $this->markFailed('PROCESSOR_UNAVAILABLE', $exception->getMessage().' Попробуйте обработать документ позже.');

            return;
        }
        // включает TimeoutExceededException (задание превысило $timeout) — его подкласс
        if ($exception instanceof MaxAttemptsExceededException) {
            $this->markFailed('PROCESSOR_TIMEOUT', 'Обработка заняла слишком много времени. Попробуйте позже.');

            return;
        }

        // в лог — только класс исключения: текст может содержать данные подключения или документа
        Log::error('Обработка документа завершилась ошибкой', [
            'document_id' => $this->document->id,
            'exception' => $exception::class,
        ]);
        $this->markFailed('INTERNAL_ERROR', 'Внутренняя ошибка при обработке документа. Попробуйте позже.');
    }

    private function saveResult(Template $template, Extraction $extraction): void
    {
        $fieldIds = $template->fields->pluck('id', 'name');

        DB::transaction(function () use ($extraction, $fieldIds): void {
            // документ удалили, пока сервис его обрабатывал, — результат некуда записать
            $document = Document::whereKey($this->document->id)->lockForUpdate()->first();
            if ($document === null) {
                return;
            }

            $result = $document->results()->create([
                'status' => 'done',
                'llm_used' => $extraction->llmUsed,
                'processing_ms' => $extraction->processingMs,
                'needs_review' => $extraction->needsReview,
                'warnings' => $extraction->warnings ?: null,
                'ocr_pages' => $extraction->ocrPages,
                'ocr_confidence' => $extraction->ocrConfidence,
                'text' => $extraction->text,
            ]);

            foreach ($extraction->fields as $field) {
                // ответ сервиса — внешние данные: сохраняются только поля шаблона
                if (! $fieldIds->has($field->name)) {
                    continue;
                }
                $result->values()->create([
                    'field_id' => $fieldIds[$field->name],
                    'field_name' => $field->name,
                    'value' => $field->value,
                    'source' => $field->source,
                    'confidence' => $field->confidence,
                    'raw' => $field->raw,
                    'start_offset' => $field->start,
                    'end_offset' => $field->end,
                    'needs_review' => $field->needsReview,
                    'issues' => $field->issues ?: null,
                    'alternatives' => $field->alternatives ?: null,
                ]);
            }

            $document->forceFill([
                'status' => DocumentStatus::Done,
                'has_text_layer' => $extraction->hasTextLayer,
                'pages' => $extraction->pages,
            ])->save();
        });
    }

    private function markFailed(string $code, string $message): void
    {
        DB::transaction(function () use ($code, $message): void {
            $document = Document::whereKey($this->document->id)->lockForUpdate()->first();
            if ($document === null) {
                return;
            }
            $document->results()->create([
                'status' => 'failed',
                'error_code' => $code,
                'error_message' => $message,
            ]);
            $document->forceFill(['status' => DocumentStatus::Failed])->save();
        });
    }
}
