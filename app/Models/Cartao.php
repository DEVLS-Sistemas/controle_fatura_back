<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cartao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cartoes';

    protected $fillable = [
        'user_id',
        'nome',
        'bandeira',
        'banco',
        'ultimos_digitos',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'cartao_id');
    }
}
