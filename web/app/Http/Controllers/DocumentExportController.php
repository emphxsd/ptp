<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Export;
use App\Models\Template;
use App\Support\DocumentFilters;
use App\Support\ResultExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Выгрузка результатов: одного документа или выборки из истории (с теми же фильтрами, что в списке). */
class DocumentExportController extends Controller
{
    private const CONTENT_TYPES = ['csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json; charset=UTF-8'];

    private const CHUNK = 200;

    public function document(Document $document, string $format): StreamedResponse
    {
        // чужой или ещё не обработанный документ — 404
        abort_unless(in_array($format, ResultExport::FORMATS, true), 404);
        abort_unless($document->user_id === Auth::id() && $document->status === DocumentStatus::Done, 404);
        $result = $document->latestResult()->with(['values' => fn ($q) => $q->orderBy('id')->with('field')])->first();
        abort_if($result === null, 404);

        $this->record([$result->id], $format);
        AuditLog::record('document_exported', Auth::user(), ['document_id' => $document->id, 'format' => $format]);

        return response()->streamDownload(function () use ($document, $result, $format): void {
            if ($format === 'json') {
                echo ResultExport::json(ResultExport::documentData($document, $result));

                return;
            }
            ResultExport::outputCsv(ResultExport::documentRows($result));
        }, "result-{$document->id}.{$format}", $this->headers($format));
    }

    public function selection(Request $request): StreamedResponse
    {
        $format = $request->query('format');
        abort_unless(is_string($format) && in_array($format, ResultExport::FORMATS, true), 404);
        $user = Auth::user();
        $filters = DocumentFilters::fromInput($user, $request->query());

        // столбцы CSV — поля шаблона, поэтому выборка в CSV — документы одного шаблона
        $template = $filters->template === null ? null : Template::with('fields')->find($filters->template);
        abort_if($format === 'csv' && $template === null, 422, 'Для выгрузки в CSV выберите шаблон в фильтре.');

        $query = $filters->apply($user->documents()->getQuery())->where('status', DocumentStatus::Done);
        $limit = (int) config('documents.max_export_documents');
        abort_if((clone $query)->count() > $limit, 422, "Слишком много документов для выгрузки ({$limit} максимум). Уточните фильтр.");

        $query->orderByDesc('uploaded_at')->orderByDesc('id');

        // запись о выгрузке — до отправки: лёгкий запрос только номеров результатов
        $resultIds = [];
        foreach ((clone $query)->with('latestResult:id,extraction_results.document_id')->get(['documents.id']) as $document) {
            if ($document->latestResult !== null) {
                $resultIds[] = $document->latestResult->id;
            }
        }
        $this->record($resultIds, $format);
        AuditLog::record('documents_exported', $user, [
            'count' => count($resultIds), 'format' => $format, 'template_id' => $template?->id,
        ]);

        // документы читаются порциями со значениями и полями шаблона — память не растёт с размером выборки
        $documents = fn () => $query->with([
            'template:id,name',
            'latestResult.values' => fn ($q) => $q->orderBy('id')->with('field'),
        ])->lazy(self::CHUNK)->filter(fn (Document $document) => $document->latestResult !== null);

        return response()->streamDownload(function () use ($documents, $template, $format): void {
            if ($format === 'json') {
                ResultExport::outputJsonList($documents()->map(
                    fn (Document $document) => ResultExport::documentData($document, $document->latestResult),
                ));

                return;
            }
            ResultExport::outputCsv((function () use ($documents, $template) {
                yield ResultExport::selectionHeader($template);
                foreach ($documents() as $document) {
                    yield ResultExport::selectionRow($template, $document, $document->latestResult);
                }
            })());
        }, 'results-'.now()->timezone(config('app.display_timezone'))->format('Y-m-d').".{$format}", $this->headers($format));
    }

    /** @param  list<int>  $resultIds */
    private function record(array $resultIds, string $format): void
    {
        $now = now();
        // одной вставкой на порцию, а не запросом на каждый результат
        foreach (array_chunk($resultIds, self::CHUNK) as $chunk) {
            Export::insert(array_map(fn (int $resultId) => [
                'result_id' => $resultId, 'format' => $format, 'destination' => 'download',
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    /** @return array<string, string> */
    private function headers(string $format): array
    {
        return [
            'Content-Type' => self::CONTENT_TYPES[$format],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
    }
}
