<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CartaoNumero extends Model
{
    use HasFactory, SoftDeletes;

    public const TIPO_FISICO = 'fisico';
    public const TIPO_VIRTUAL = 'virtual';
    public const TIPO_ADICIONAL = 'adicional';

    public const TIPOS = [
        self::TIPO_FISICO,
        self::TIPO_VIRTUAL,
        self::TIPO_ADICIONAL,
    ];

    protected $table = 'cartao_numeros';

    protected $fillable = [
        'cartao_bandeira_id',
        'ultimos_digitos',
        'tipo',
        'apelido',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'cartao_bandeira_id' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function bandeira(): BelongsTo
    {
        return $this->belongsTo(CartaoBandeira::class, 'cartao_bandeira_id');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'cartao_numero_id');
    }
}
