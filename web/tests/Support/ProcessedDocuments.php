<?php

namespace Tests\Support;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Документы с готовым результатом обработки — без обращения к сервису (Storage::fake('documents') — в тесте). */
trait ProcessedDocuments
{
    protected function invoiceTemplate(): Template
    {
        return Template::where('is_system', true)->where('name', 'Счёт на оплату')->with('fields')->firstOrFail();
    }

    /**
     * Обработанный счёт: значения полей по имени; null — поле не найдено.
     *
     * @param  array<string, string|null>  $values
     * @param  array<string, mixed>  $attributes  атрибуты документа (file_name, status, uploaded_at, needs_review)
     */
    protected function processedInvoice(User $owner, array $values = [], array $attributes = []): Document
    {
        $template = $this->invoiceTemplate();
        $document = new Document([
            'template_id' => $template->id, 'file_name' => $attributes['file_name'] ?? 'счёт.pdf',
            'file_format' => 'pdf', 'size_bytes' => 8, 'sha256' => str_repeat('a', 64),
        ]);
        $document->forceFill([
            'storage_key' => $owner->id.'/'.uniqid().'.pdf',
            'status' => $attributes['status'] ?? DocumentStatus::Done,
            'uploaded_at' => $attributes['uploaded_at'] ?? now(),
        ])->user()->associate($owner)->save();
        Storage::disk('documents')->put($document->storage_key, '%PDF-1.4');

        $values += ['number' => '15', 'supplier_inn' => '7707083893', 'total' => '15000.00'];
        $needsReview = $attributes['needs_review'] ?? false;
        $result = $document->results()->create(['status' => 'done', 'text' => 'Счёт № 15', 'needs_review' => $needsReview]);
        foreach ($template->fields as $field) {
            $found = isset($values[$field->name]);
            $result->values()->create([
                'field_id' => $field->id,
                'field_name' => $field->name,
                'value' => $values[$field->name] ?? null,
                'source' => $found ? 'rule' : null,
                'confidence' => $found ? 0.9 : 0.0,
                'needs_review' => $needsReview && $field->name === 'total',
            ]);
        }

        return $document;
    }
}
