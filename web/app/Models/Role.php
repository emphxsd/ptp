<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property RoleName $name
 * @property string|null $description
 */
#[Fillable(['name', 'description'])]
class Role extends Model
{
    protected function casts(): array
    {
        return ['name' => RoleName::class];
    }

    public static function idFor(RoleName $name): int
    {
        return (int) static::where('name', $name->value)->valueOrFail('id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
