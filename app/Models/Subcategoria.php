<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subcategoria extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'subcategorias';

    protected $fillable = [
        'user_id',
        'nome',
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

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(
            Categoria::class,
            'categoria_subcategoria',
            'subcategoria_id',
            'categoria_id'
        )->withPivot('cor')->withTimestamps();
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'subcategoria_id');
    }

    public function estabelecimentosPadrao(): HasMany
    {
        return $this->hasMany(Estabelecimento::class, 'subcategoria_padrao_id');
    }
}
