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
        'pessoa_id',
        'responsavel_id',
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
        'pessoa_id' => 'integer',
        'responsavel_id' => 'integer',
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

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'fatura_id');
    }

    public function transacoesGeradas(): HasMany
    {
        return $this->hasMany(Transacao::class, 'fatura_origem_id');
    }
}
