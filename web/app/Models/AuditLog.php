<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Журнал действий: вход, выход, загрузка, экспорт, администрирование.
 * В details — только служебные сведения (IP, идентификаторы), без паролей и содержимого документов.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'action', 'details'])]
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /**
     * Записи старше срока хранения (config/audit.php) удаляются командой model:prune по расписанию.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('audit.retention_days')));
    }

    /** @param  array<string, mixed>  $details */
    public static function record(string $action, ?User $user = null, array $details = []): self
    {
        return static::create(['user_id' => $user?->id, 'action' => $action, 'details' => $details]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
