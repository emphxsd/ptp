<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Временные файлы загрузки Livewire (livewire-tmp) — копии документов с персональными данными.
 * После сохранения страница удаляет их сама, но брошенные загрузки (не отправленная форма, отклонённый файл)
 * Livewire чистит только при следующей загрузке и через сутки. Команда удаляет их по расписанию.
 */
class PruneTemporaryUploads extends Command
{
    protected $signature = 'app:prune-temporary-uploads {--hours=1 : Удалять файлы старше, часов}';

    protected $description = 'Удалить брошенные временные файлы загрузки документов';

    private const DEFAULT_DIRECTORY = 'livewire-tmp';

    public function handle(): int
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));
        $directory = config('livewire.temporary_file_upload.directory') ?: self::DEFAULT_DIRECTORY;
        $threshold = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();

        $deleted = 0;
        foreach ($disk->files($directory) as $path) {
            if ($disk->lastModified($path) < $threshold) {
                $disk->delete($path);
                $deleted++;
            }
        }
        $this->info("Удалено временных файлов: {$deleted}");

        return self::SUCCESS;
    }
}
