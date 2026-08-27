<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transacao extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'transacoes';

    public const TIPO_PURCHASE = 'purchase';
    public const TIPO_PAYMENT = 'payment';
    public const TIPO_REFUND = 'refund';
    public const TIPO_ADVANCE = 'advance';
    /** Encargos da fatura (juros, multa, IOF, etc.) — não é compra nem pagamento. */
    public const TIPO_FEE = 'fee';
    /** Saldo restante da fatura anterior (lançamento operacional do extrato). */
    public const TIPO_CARRYOVER = 'carryover';

    public const TIPOS = [
        self::TIPO_PURCHASE,
        self::TIPO_PAYMENT,
        self::TIPO_REFUND,
        self::TIPO_ADVANCE,
        self::TIPO_FEE,
        self::TIPO_CARRYOVER,
    ];

    public const TIPOS_OPERACIONAIS = [
        self::TIPO_PAYMENT,
        self::TIPO_REFUND,
        self::TIPO_ADVANCE,
        self::TIPO_FEE,
        self::TIPO_CARRYOVER,
    ];

    public const TIPOS_LABELS = [
        self::TIPO_PURCHASE => 'Compra',
        self::TIPO_PAYMENT => 'Pagamento',
        self::TIPO_REFUND => 'Estorno',
        self::TIPO_ADVANCE => 'Antecipação',
        self::TIPO_FEE => 'Encargo',
        self::TIPO_CARRYOVER => 'Saldo anterior',
    ];

    public const GRUPO_CARTAO = 'cartao';
    public const GRUPO_PAGAMENTOS_FINANCIAMENTOS = 'pagamentos_financiamentos';
    public const GRUPO_PAGAMENTOS_FINANCIAMENTOS_LABEL = 'Pagamentos e Financiamentos';
    public const GRUPO_OPERACIONAIS = 'operacionais';

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

    public const CONCILIACAO_NAO_CONCILIADA = 'nao_conciliada';
    public const CONCILIACAO_PENDENTE = 'pendente';
    public const CONCILIACAO_CONCILIADA = 'conciliada';
    public const CONCILIACAO_REJEITADA = 'rejeitada';

    public const CONCILIACAO_STATUS = [
        self::CONCILIACAO_NAO_CONCILIADA,
        self::CONCILIACAO_PENDENTE,
        self::CONCILIACAO_CONCILIADA,
        self::CONCILIACAO_REJEITADA,
    ];

    public const CONCILIACAO_LABELS = [
        self::CONCILIACAO_NAO_CONCILIADA => 'Não conciliada',
        self::CONCILIACAO_PENDENTE => 'Conciliação pendente',
        self::CONCILIACAO_CONCILIADA => 'Conciliada',
        self::CONCILIACAO_REJEITADA => 'Conciliação rejeitada',
    ];

    public const PRECISA_CONCILIAR_LABEL = 'Compra manual · conciliar com a fatura';
    public const CONCILIADA_COM_MANUAL_LABEL = 'Conciliada com compra manual';
    public const SUGESTAO_CONCILIACAO_LABEL = 'Pode ser a compra manual';

    protected $fillable = [
        'user_id',
        'fatura_id',
        'fatura_origem_id',
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
        'eh_assinatura',
        'categoria_id',
        'subcategoria_id',
        'responsavel_id',
        'observacoes',
        'descricao',
        'descricao_fatura',
        'status_conciliacao',
        'lancamento_id',
        'ignorar_no_total',
        'importada_pdf',
        'compra_manual',
        'criada_como_manual',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'fatura_id' => 'integer',
        'fatura_origem_id' => 'integer',
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
        'eh_assinatura' => 'boolean',
        'lancamento_id' => 'integer',
        'ignorar_no_total' => 'boolean',
        'importada_pdf' => 'boolean',
        'compra_manual' => 'boolean',
        'criada_como_manual' => 'boolean',
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

    public function faturaOrigem(): BelongsTo
    {
        return $this->belongsTo(Fatura::class, 'fatura_origem_id');
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

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Transacao::class, 'lancamento_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(CompraAnexo::class, 'transacao_id');
    }

    public function historicos(): HasMany
    {
        return $this->hasMany(CompraHistorico::class, 'transacao_id');
    }

    public function repasses(): HasMany
    {
        return $this->hasMany(Repasse::class, 'transacao_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isCompraManualRow(array $row): bool
    {
        if (($row['tipo'] ?? null) !== self::TIPO_PURCHASE) {
            return false;
        }

        if (array_key_exists('compra_manual', $row) && $row['compra_manual'] !== null) {
            return filter_var($row['compra_manual'], FILTER_VALIDATE_BOOLEAN);
        }

        return !filter_var($row['importada_pdf'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function precisaConciliarRow(array $row): bool
    {
        if (!self::isCompraManualRow($row)) {
            return false;
        }

        return in_array($row['status_conciliacao'] ?? null, [
            self::CONCILIACAO_NAO_CONCILIADA,
            self::CONCILIACAO_PENDENTE,
        ], true);
    }

    /**
     * Texto do que foi comprado (observação), não o nome da maquininha.
     *
     * @param array<string, mixed> $row
     */
    public static function textoCompraFromRow(array $row): ?string
    {
        $obs = trim((string) ($row['observacoes'] ?? ''));
        if ($obs !== '') {
            return $obs;
        }

        $desc = trim((string) ($row['descricao'] ?? ''));

        return $desc !== '' ? $desc : null;
    }
}
