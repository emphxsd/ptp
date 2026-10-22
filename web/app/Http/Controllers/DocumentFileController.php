<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Исходный файл документа — только владельцу и только как вложение (без показа в браузере). */
class DocumentFileController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        // чужой документ — 404, как несуществующий
        abort_unless($document->user_id === Auth::id(), 404);
        $disk = Storage::disk(Document::DISK);
        abort_unless($disk->exists($document->storage_key), 404);
        // исходный файл — персональные данные: каждое скачивание в журнале
        AuditLog::record('document_downloaded', Auth::user(), ['document_id' => $document->id]);

        return $disk->download($document->storage_key, $document->file_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
