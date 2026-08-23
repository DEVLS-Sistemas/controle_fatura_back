<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToUser
{
    public function initializeBelongsToUser(): void
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, ['user_id'])));
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function scopeForAuthUser(Builder $query): Builder
    {
        $userId = Auth::id();

        if (!$userId) {
            return $query->whereRaw('1 = 0');
        }

        return $this->scopeForUser($query, (int) $userId);
    }
}
