<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $result_id
 * @property string $format
 * @property string|null $destination
 */
#[Fillable(['result_id', 'format', 'destination'])]
class Export extends Model
{
    /** @return BelongsTo<ExtractionResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(ExtractionResult::class, 'result_id');
    }
}
