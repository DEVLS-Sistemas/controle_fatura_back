<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstabelecimentoCategoria extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'estabelecimento_categorias';

    protected $fillable = [
        'user_id',
        'estabelecimento',
        'categoria_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'categoria_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }
}
