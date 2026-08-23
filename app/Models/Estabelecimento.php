<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estabelecimento extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'estabelecimentos';

    protected $fillable = [
        'user_id',
        'nome',
        'loja_id',
        'categoria_padrao_id',
        'subcategoria_padrao_id',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'loja_id' => 'integer',
        'categoria_padrao_id' => 'integer',
        'subcategoria_padrao_id' => 'integer',
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

    public function loja(): BelongsTo
    {
        return $this->belongsTo(Loja::class, 'loja_id');
    }

    public function categoriaPadrao(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_padrao_id');
    }

    public function subcategoriaPadrao(): BelongsTo
    {
        return $this->belongsTo(Subcategoria::class, 'subcategoria_padrao_id');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'estabelecimento_id');
    }
}
