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

    protected $hidden = [
        'bandeira_periodo_key',
    ];

    protected $fillable = [
        'user_id',
        'pessoa_id',
        'responsavel_id',
        'cartao_id',
        'cartao_bandeira_id',
        'mes',
        'ano',
        'valor_total',
        'valor_fatura',
        'arquivo_pdf',
        'arquivo_csv',
        'anexo_hash',
        'anexo_pdf_id',
        'anexo_csv_id',
        'status',
        'erro_mensagem',
        'erro_codigo',
        'processado_em',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'pessoa_id' => 'integer',
        'responsavel_id' => 'integer',
        'cartao_id' => 'integer',
        'cartao_bandeira_id' => 'integer',
        'mes' => 'integer',
        'ano' => 'integer',
        'valor_total' => 'decimal:2',
        'valor_fatura' => 'decimal:2',
        'anexo_pdf_id' => 'integer',
        'anexo_csv_id' => 'integer',
        'processado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public const COMPRAS_NAO_CONCILIADAS_LABEL = 'Compras ainda não conciliadas';

    /**
     * Totais do detalhe: extrato da fatura + compras manuais ainda abertas.
     *
     * @return array{
     *     valor_extrato: float,
     *     valor_nao_conciliado: float,
     *     valor_total_com_pendencias: float,
     *     tem_compras_nao_conciliadas: bool,
     *     compras_nao_conciliadas_label: ?string
     * }
     */
    public static function totaisConciliacaoPayload(float $valorExtrato, float $valorNaoConciliado): array
    {
        $extrato = round($valorExtrato, 2);
        $pendente = round(max($valorNaoConciliado, 0), 2);
        $temPendencias = $pendente > 0.009;

        return [
            'valor_extrato' => $extrato,
            'valor_nao_conciliado' => $pendente,
            'valor_total_com_pendencias' => round($extrato + $pendente, 2),
            'tem_compras_nao_conciliadas' => $temPendencias,
            'compras_nao_conciliadas_label' => $temPendencias
                ? self::COMPRAS_NAO_CONCILIADAS_LABEL
                : null,
        ];
    }

    /**
     * Fatura processada: o valor do PDF é a fonte da verdade.
     * Linhas a mais (parcela materializada, centavo, etc.) não inflacionam o extrato.
     */
    public function valorExtratoBase(float $calculadoDasLinhas): float
    {
        $travado = $this->valorFaturaTravado();

        return $travado !== null ? $travado : round($calculadoDasLinhas, 2);
    }

    /**
     * Total gravado no cabeçalho do PDF (após sanitizar limite vs soma no parser).
     */
    public function valorFaturaTravado(): ?float
    {
        if ($this->status !== 'processada' || $this->valor_fatura === null) {
            return null;
        }

        return round((float) $this->valor_fatura, 2);
    }

    public static function isOwnedStoragePath(?string $relative, int $userId): bool
    {
        if ($relative === null || $relative === '') {
            return false;
        }

        $relative = str_replace('\\', '/', $relative);
        $prefix = 'faturas/'.$userId.'/';

        return ! str_contains($relative, '..') && str_starts_with($relative, $prefix);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Responsavel::class, 'responsavel_id');
    }

    public function cartao(): BelongsTo
    {
        return $this->belongsTo(Cartao::class, 'cartao_id');
    }

    public function cartaoBandeira(): BelongsTo
    {
        return $this->belongsTo(CartaoBandeira::class, 'cartao_bandeira_id');
    }

    public function anexoPdf(): BelongsTo
    {
        return $this->belongsTo(Anexo::class, 'anexo_pdf_id');
    }

    public function anexoCsv(): BelongsTo
    {
        return $this->belongsTo(Anexo::class, 'anexo_csv_id');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'fatura_id');
    }

    public function transacoesGeradas(): HasMany
    {
        return $this->hasMany(Transacao::class, 'fatura_origem_id');
    }
}
