<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\ExtractionResult;
use App\Models\Template;
use App\Models\User;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorUnavailableException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class ProcessDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRACT_URL = '*/extract';

    private const TEXT = "Счёт на оплату № 15 от 01.03.2025\nПоставщик: ООО «Ромашка», ИНН 7707083893\nИтого к оплате: 15 000,00";

    private Template $template;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Http::preventStrayRequests();
        config(['services.processor.url' => 'http://processor.test', 'services.processor.token' => 'test-token']);
        $this->app->forgetInstance(ProcessorClient::class);

        $this->user = User::factory()->create();
        $this->template = Template::where('is_system', true)->where('name', 'Счёт на оплату')->with('fields')->firstOrFail();
    }

    private function document(bool $useLlm = true): Document
    {
        $document = new Document([
            'template_id' => $this->template->id, 'file_name' => 'счёт.pdf', 'file_format' => 'pdf',
            'size_bytes' => 9, 'sha256' => hash('sha256', '%PDF-1.4'),
        ]);
        $document->forceFill(['storage_key' => $this->user->id.'/test.pdf', 'use_llm' => $useLlm])
            ->user()->associate($this->user)->save();
        Storage::disk('documents')->put($document->storage_key, '%PDF-1.4');

        return $document;
    }

    /** @return array<string, mixed> ответ /extract в формате сервиса (processor/app/schemas.py: ExtractionResult) */
    private function extractData(): array
    {
        $start = mb_strpos(self::TEXT, '7707083893');

        return [
            'document' => [
                'format' => 'pdf', 'pages' => 1, 'pages_without_text' => 0, 'has_text_layer' => true,
                'chars' => mb_strlen(self::TEXT), 'ocr_pages' => 0, 'ocr_confidence' => null,
            ],
            'fields' => [
                [
                    'name' => 'supplier_inn', 'value' => '7707083893', 'source' => 'rule', 'raw' => '7707083893',
                    'start' => $start, 'end' => $start + 10, 'confidence' => 0.95, 'needs_review' => false,
                    'alternatives' => [], 'issues' => [],
                ],
                [
                    'name' => 'total', 'value' => '15000.00', 'source' => 'rule', 'raw' => '15 000,00',
                    'start' => 90, 'end' => 99, 'confidence' => 0.55, 'needs_review' => true,
                    'alternatives' => ['1500.00'], 'issues' => ['LOW_CONFIDENCE'],
                ],
                [
                    'name' => 'vat', 'value' => null, 'source' => null, 'raw' => null, 'start' => null, 'end' => null,
                    'confidence' => 0.0, 'needs_review' => false, 'alternatives' => [], 'issues' => [],
                ],
            ],
            'llm_used' => false,
            'processing_ms' => 412,
            'text' => self::TEXT,
            'warnings' => ['LLM_UNAVAILABLE'],
            'needs_review' => true,
            'review_fields' => ['total'],
        ];
    }

    private function fakeExtract(mixed $response): void
    {
        Http::fake([self::EXTRACT_URL => function (Request $request) use ($response) {
            // как настоящий Guzzle: после отправки поток файла закрыт — задание не должно закрывать его второй раз
            foreach ($request->data() as $part) {
                $contents = $part['contents'] ?? null;
                if ($contents instanceof StreamInterface) {
                    $contents->close();
                } elseif (is_resource($contents)) {
                    fclose($contents);
                }
            }

            return $response;
        }]);
    }

    private function process(Document $document): void
    {
        (new ProcessDocument($document))->handle(app(ProcessorClient::class));
    }

    public function test_successful_extraction_is_saved_with_values_and_text(): void
    {
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));
        $document = $this->document(useLlm: false);

        $this->process($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Done, $document->status);
        $this->assertTrue($document->has_text_layer);
        $this->assertSame(1, $document->pages);

        $result = $document->latestResult;
        $this->assertSame('done', $result->status);
        $this->assertTrue($result->needs_review);
        $this->assertSame(412, $result->processing_ms);
        $this->assertSame(['LLM_UNAVAILABLE'], $result->warnings);
        $this->assertSame(self::TEXT, $result->text);

        $values = $result->values()->get()->keyBy('field_name');
        $this->assertSame(['supplier_inn', 'total', 'vat'], $values->keys()->all());
        $inn = $values['supplier_inn'];
        $this->assertSame('7707083893', $inn->value);
        $this->assertSame('rule', $inn->source);
        $this->assertSame(0.95, $inn->confidence);
        $this->assertSame(mb_strpos(self::TEXT, '7707083893'), $inn->start_offset);
        $this->assertSame($this->template->fields->firstWhere('name', 'supplier_inn')->id, $inn->field_id);
        $this->assertTrue($values['total']->needs_review);
        $this->assertSame(['LOW_CONFIDENCE'], $values['total']->issues);
        $this->assertSame(['1500.00'], $values['total']->alternatives);
        $this->assertNull($values['vat']->value);

        Http::assertSent(function (Request $request): bool {
            $template = json_decode((string) collect($request->data())->firstWhere('name', 'template')['contents'], true);

            return $request->url() === 'http://processor.test/extract'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && collect($request->data())->firstWhere('name', 'use_llm')['contents'] === 'false'
                && collect($request->data())->firstWhere('name', 'include_text')['contents'] === 'true'
                && $template['fields'] === $this->template->toProcessorPayload()['fields'];
        });
    }

    public function test_user_file_name_is_not_sent_to_the_service(): void
    {
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));
        $document = $this->document();
        $document->forceFill(['file_name' => 'Паспорт Иванов И.И.pdf'])->save();

        $this->process($document);

        Http::assertSent(function (Request $request): bool {
            $file = collect($request->data())->firstWhere('name', 'file');

            return $file['filename'] === 'document.pdf';
        });
    }

    public function test_local_llm_promise_is_enforced_by_the_service(): void
    {
        // флажок обещает «никуда не передаётся» — сервис должен отказаться от облачной LLM, если настроен на неё
        config(['documents.llm_external' => false]);
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));

        $this->process($this->document(useLlm: true));

        Http::assertSent(fn (Request $request): bool => collect($request->data())->firstWhere('name', 'llm_local_only')['contents'] === 'true');
    }

    public function test_external_llm_promise_allows_cloud_when_configured(): void
    {
        config(['documents.llm_external' => true]);
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));

        $this->process($this->document(useLlm: true));

        Http::assertSent(fn (Request $request): bool => collect($request->data())->firstWhere('name', 'llm_local_only')['contents'] === 'false');
    }

    public function test_repeated_delivery_of_finished_document_is_ignored(): void
    {
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));
        $document = $this->document();
        $this->process($document);

        // то же задание доставлено ещё раз (сбой обработчика очереди): повторной отправки в сервис и LLM нет
        $this->process($document);

        $this->assertSame(1, $document->results()->count());
        Http::assertSentCount(1);
    }

    public function test_document_deleted_during_processing_is_skipped(): void
    {
        $this->fakeExtract(Http::response(['success' => true, 'data' => $this->extractData(), 'error' => null]));
        $document = $this->document();
        $job = new ProcessDocument($document);
        $document->delete();

        $job->handle(app(ProcessorClient::class));
        $job->failed(new ProcessorUnavailableException('Сервис обработки недоступен.'));

        $this->assertSame(0, ExtractionResult::count());
    }

    public function test_exhausted_attempts_after_timeout_are_reported_as_timeout(): void
    {
        $document = $this->document();

        (new ProcessDocument($document))->failed(new MaxAttemptsExceededException('App\Jobs\ProcessDocument has been attempted too many times.'));

        $this->assertSame('PROCESSOR_TIMEOUT', $document->fresh()->latestResult->error_code);
    }

    public function test_service_configuration_errors_are_not_shown_to_user(): void
    {
        $this->fakeExtract(Http::response(['success' => false, 'data' => null, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Требуется токен']], 401));
        $document = $this->document();

        try {
            $this->process($document);
        } catch (ProcessorUnavailableException $unavailable) {
            (new ProcessDocument($document))->failed($unavailable);
        }

        $message = $document->fresh()->latestResult->error_message;
        $this->assertStringNotContainsString('PROCESSOR_API_TOKEN', $message);
        $this->assertStringNotContainsString('401', $message);
    }

    public function test_file_removed_after_check_fails_the_document(): void
    {
        $document = $this->document();
        Storage::shouldReceive('disk')->andReturn($disk = \Mockery::mock(Filesystem::class));
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation($document->storage_key));

        $this->process($document);

        $this->assertSame('FILE_MISSING', $document->fresh()->latestResult->error_code);
    }

    public function test_job_lock_expires(): void
    {
        $job = new ProcessDocument($this->document());

        // блокировка уникальности должна истекать, иначе после сбоя обработчика документ нельзя отправить повторно
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));
    }

    public function test_rejected_document_is_marked_failed_without_retry(): void
    {
        $this->fakeExtract(Http::response([
            'success' => false, 'data' => null,
            'error' => ['code' => 'PASSWORD_PROTECTED', 'message' => 'PDF защищён паролем'],
        ], 422));
        $document = $this->document();

        $this->process($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $result = $document->latestResult;
        $this->assertSame('failed', $result->status);
        $this->assertSame('PASSWORD_PROTECTED', $result->error_code);
        $this->assertSame('PDF защищён паролем', $result->error_message);
        $this->assertSame(0, $result->values()->count());
    }

    public function test_unavailable_service_is_retried_by_the_queue(): void
    {
        $this->fakeExtract(Http::response('Bad gateway', 502));
        $document = $this->document();

        try {
            $this->process($document);
            $this->fail('ожидалось исключение для повторной попытки');
        } catch (ProcessorUnavailableException) {
        }

        // до исчерпания попыток документ остаётся в обработке, результата с ошибкой нет
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        $this->assertSame(0, ExtractionResult::count());
    }

    public function test_busy_service_is_retried_by_the_queue(): void
    {
        $this->fakeExtract(Http::response([
            'success' => false, 'data' => null,
            'error' => ['code' => 'SERVICE_BUSY', 'message' => 'Сервис перегружен'],
        ], 503));

        $this->expectException(ProcessorUnavailableException::class);

        $this->process($this->document());
    }

    public function test_document_is_marked_failed_when_retries_are_exhausted(): void
    {
        $document = $this->document();

        (new ProcessDocument($document))->failed(new ProcessorUnavailableException('Сервис обработки недоступен.'));

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('PROCESSOR_UNAVAILABLE', $document->latestResult->error_code);
    }

    public function test_unexpected_failure_does_not_leak_details_to_user(): void
    {
        $document = $this->document();

        (new ProcessDocument($document))->failed(new \RuntimeException('SQLSTATE[08006] host=10.0.0.5 password=...'));

        $result = $document->fresh()->latestResult;
        $this->assertSame('INTERNAL_ERROR', $result->error_code);
        $this->assertStringNotContainsString('SQLSTATE', $result->error_message);
    }

    public function test_deleted_template_fails_the_document(): void
    {
        $document = $this->document();
        $document->forceFill(['template_id' => null])->save();

        $this->process($document);

        $this->assertSame(DocumentStatus::Failed, $document->fresh()->status);
        $this->assertSame('TEMPLATE_UNAVAILABLE', $document->fresh()->latestResult->error_code);
        Http::assertNothingSent();
    }

    public function test_missing_file_fails_the_document(): void
    {
        $document = $this->document();
        Storage::disk('documents')->delete($document->storage_key);

        $this->process($document);

        $this->assertSame('FILE_MISSING', $document->fresh()->latestResult->error_code);
        Http::assertNothingSent();
    }

    public function test_malformed_service_answer_is_retried(): void
    {
        $this->fakeExtract(Http::response(['success' => true, 'data' => ['fields' => 'not a list'], 'error' => null]));

        $this->expectException(ProcessorUnavailableException::class);

        $this->process($this->document());
    }

    public function test_values_for_unknown_fields_are_ignored(): void
    {
        $data = $this->extractData();
        $data['fields'][] = [
            'name' => 'injected', 'value' => 'x', 'source' => 'llm', 'raw' => 'x', 'start' => 0, 'end' => 1,
            'confidence' => 0.4, 'needs_review' => true, 'alternatives' => [], 'issues' => [],
        ];
        $this->fakeExtract(Http::response(['success' => true, 'data' => $data, 'error' => null]));
        $document = $this->document();

        $this->process($document);

        $this->assertSame(
            ['supplier_inn', 'total', 'vat'],
            $document->fresh()->latestResult->values()->orderBy('id')->pluck('field_name')->all(),
        );
    }

    public function test_reprocessing_after_failure_keeps_previous_results_as_history(): void
    {
        Http::fake([self::EXTRACT_URL => Http::sequence()
            ->push(['success' => false, 'data' => null, 'error' => ['code' => 'DOCUMENT_READ_ERROR', 'message' => 'Файл повреждён']], 422)
            ->push(['success' => true, 'data' => $this->extractData(), 'error' => null]),
        ]);
        $document = $this->document();
        $this->process($document);

        // «Повторить» на странице документов переводит документ из «Ошибка» в «Загружен»
        $document->fresh()->forceFill(['status' => DocumentStatus::Uploaded])->save();
        $this->process($document);

        $this->assertSame(['failed', 'done'], $document->results()->orderBy('id')->pluck('status')->all());
        $this->assertSame(DocumentStatus::Done, $document->fresh()->status);
    }
}
