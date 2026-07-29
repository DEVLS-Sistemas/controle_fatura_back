<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transacoes';

    public const TIPO_PURCHASE = 'purchase';
    public const TIPO_PAYMENT = 'payment';
    public const TIPO_REFUND = 'refund';
    public const TIPO_ADVANCE = 'advance';

    public const TIPOS = [
        self::TIPO_PURCHASE,
        self::TIPO_PAYMENT,
        self::TIPO_REFUND,
        self::TIPO_ADVANCE,
    ];

    protected $fillable = [
        'user_id',
        'fatura_id',
        'data',
        'estabelecimento',
        'valor',
        'parcelas_total',
        'parcela_atual',
        'valor_parcela',
        'tipo',
        'categoria_id',
        'responsavel_id',
        'observacoes',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'fatura_id' => 'integer',
        'data' => 'date',
        'valor' => 'decimal:2',
        'parcelas_total' => 'integer',
        'parcela_atual' => 'integer',
        'valor_parcela' => 'decimal:2',
        'categoria_id' => 'integer',
        'responsavel_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class, 'fatura_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Responsavel::class, 'responsavel_id');
    }
}
