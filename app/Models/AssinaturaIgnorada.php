<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssinaturaIgnorada extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'assinaturas_ignoradas';

    public const TIPO_CHAVE_LOJA = 'loja';
    public const TIPO_CHAVE_ESTABELECIMENTO = 'estabelecimento';

    public const TIPOS_CHAVE = [
        self::TIPO_CHAVE_LOJA,
        self::TIPO_CHAVE_ESTABELECIMENTO,
    ];

    protected $fillable = [
        'user_id',
        'tipo_chave',
        'referencia_id',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'referencia_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
