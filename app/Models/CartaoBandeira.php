<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CartaoBandeira extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cartao_bandeiras';

    protected $fillable = [
        'cartao_id',
        'bandeira',
        'limite_credito',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'cartao_id' => 'integer',
        'limite_credito' => 'float',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class, 'cartao_id');
    }

    public function numeros(): HasMany
    {
        return $this->hasMany(CartaoNumero::class, 'cartao_bandeira_id');
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'cartao_bandeira_id');
    }
}
