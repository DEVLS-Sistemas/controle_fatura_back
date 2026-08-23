<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fatura extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'faturas';

    protected $fillable = [
        'user_id',
        'cartao_id',
        'cartao_bandeira_id',
        'mes',
        'ano',
        'valor_total',
        'arquivo_pdf',
        'arquivo_csv',
        'status',
        'erro_mensagem',
        'erro_codigo',
        'processado_em',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'cartao_id' => 'integer',
        'cartao_bandeira_id' => 'integer',
        'mes' => 'integer',
        'ano' => 'integer',
        'valor_total' => 'decimal:2',
        'processado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public static function isOwnedStoragePath(?string $relative, int $userId): bool
    {
        if ($relative === null || $relative === '') {
            return false;
        }

        $relative = str_replace('\\', '/', $relative);
        $prefix = 'faturas/' . $userId . '/';

        return !str_contains($relative, '..') && str_starts_with($relative, $prefix);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class, 'cartao_id');
    }

    public function cartaoBandeira(): BelongsTo
    {
        return $this->belongsTo(CartaoBandeira::class, 'cartao_bandeira_id');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'fatura_id');
    }
}
