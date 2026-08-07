<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transacoes';

    public const TIPO_PURCHASE = 'purchase';
    public const TIPO_PAYMENT = 'payment';
    public const TIPO_REFUND = 'refund';
    public const TIPO_ADVANCE = 'advance';
    /** Encargos da fatura (juros, multa, IOF, etc.) — não é compra nem pagamento. */
    public const TIPO_FEE = 'fee';

    public const TIPOS = [
        self::TIPO_PURCHASE,
        self::TIPO_PAYMENT,
        self::TIPO_REFUND,
        self::TIPO_ADVANCE,
        self::TIPO_FEE,
    ];

    public const TIPOS_LABELS = [
        self::TIPO_PURCHASE => 'Compra',
        self::TIPO_PAYMENT => 'Pagamento',
        self::TIPO_REFUND => 'Estorno',
        self::TIPO_ADVANCE => 'Antecipação',
        self::TIPO_FEE => 'Encargo',
    ];

    /** Origem/canal da compra (independente do `tipo` contábil). */
    public const ORIGEM_COMPRAS_ONLINE = 'COMPRAS_ONLINE';
    public const ORIGEM_COMPRAS_PRESENCIAL = 'COMPRAS_PRESENCIAL';
    public const ORIGEM_PAGAMENTO_SERVICOS = 'PAGAMENTO_SERVICOS';
    public const ORIGEM_PAGAMENTO_FATURA = 'PAGAMENTO_FATURA';

    public const ORIGENS_COMPRA = [
        self::ORIGEM_COMPRAS_ONLINE,
        self::ORIGEM_COMPRAS_PRESENCIAL,
        self::ORIGEM_PAGAMENTO_SERVICOS,
        self::ORIGEM_PAGAMENTO_FATURA,
    ];

    public const ORIGENS_COMPRA_LABELS = [
        self::ORIGEM_COMPRAS_ONLINE => 'Compras online',
        self::ORIGEM_COMPRAS_PRESENCIAL => 'Compras presencial',
        self::ORIGEM_PAGAMENTO_SERVICOS => 'Pagamento de serviços',
        self::ORIGEM_PAGAMENTO_FATURA => 'Pagamento fatura',
    ];

    protected $fillable = [
        'user_id',
        'fatura_id',
        'cartao_numero_id',
        'estabelecimento_id',
        'data',
        'valor',
        'parcelas_total',
        'parcela_atual',
        'valor_parcela',
        'compra_grupo_id',
        'tipo',
        'origem_compra',
        'categoria_id',
        'subcategoria_id',
        'responsavel_id',
        'observacoes',
        'importada_pdf',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'fatura_id' => 'integer',
        'cartao_numero_id' => 'integer',
        'estabelecimento_id' => 'integer',
        'data' => 'date',
        'valor' => 'decimal:2',
        'parcelas_total' => 'integer',
        'parcela_atual' => 'integer',
        'valor_parcela' => 'decimal:2',
        'compra_grupo_id' => 'string',
        'categoria_id' => 'integer',
        'subcategoria_id' => 'integer',
        'responsavel_id' => 'integer',
        'importada_pdf' => 'boolean',
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

    public function cartaoNumero(): BelongsTo
    {
        return $this->belongsTo(CartaoNumero::class, 'cartao_numero_id');
    }

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(Subcategoria::class, 'subcategoria_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Responsavel::class, 'responsavel_id');
    }

    public function repasses(): HasMany
    {
        return $this->hasMany(Repasse::class, 'transacao_id');
    }
}
