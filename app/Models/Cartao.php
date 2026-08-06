<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cartao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cartoes';

    protected $fillable = [
        'user_id',
        'nome',
        'banco',
        'dia_limite_fatura',
        'dia_vencimento_fatura',
        'cor_fundo',
        'cor_texto',
        'ativo',
        'senha_pdf',
        'senha_pdf_regra',
    ];

    protected $hidden = [
        'senha_pdf',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dia_limite_fatura' => 'integer',
        'dia_vencimento_fatura' => 'integer',
        'ativo' => 'boolean',
        'senha_pdf' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function temSenhaPdf(): bool
    {
        return filled($this->senha_pdf);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bandeiras(): HasMany
    {
        return $this->hasMany(CartaoBandeira::class, 'cartao_id');
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'cartao_id');
    }

    /**
     * Determina mes/ano da fatura para uma data de compra,
     * com base no dia limite (fechamento) do cartão.
     *
     * Ex.: dia_limite = 5 → compras até o dia 05 entram na fatura do mês atual;
     * compras a partir do dia 06 entram na fatura do mês seguinte.
     *
     * Se dia_limite_fatura for nulo, usa o mês calendário da data (legado).
     *
     * @return array{mes: int, ano: int}
     */
    public function periodoFaturaParaData(Carbon|string $data): array
    {
        $dataRef = $data instanceof Carbon ? $data->copy() : Carbon::parse($data);

        if (empty($this->dia_limite_fatura)) {
            return [
                'mes' => (int) $dataRef->month,
                'ano' => (int) $dataRef->year,
            ];
        }

        $limiteEfetivo = min((int) $this->dia_limite_fatura, $dataRef->daysInMonth);
        $periodo = $dataRef->copy()->startOfMonth();

        if ($dataRef->day > $limiteEfetivo) {
            $periodo->addMonthNoOverflow();
        }

        return [
            'mes' => (int) $periodo->month,
            'ano' => (int) $periodo->year,
        ];
    }

    /**
     * Intervalo de competência da fatura (início/fim do ciclo) e data de vencimento.
     *
     * Com dia_limite = 5 e competência 08/2026:
     * - periodo_inicio = 06/07/2026
     * - periodo_fim = 05/08/2026
     * - data_vencimento = dia_vencimento no mês da competência
     *   (se vencimento <= limite, cai no mês seguinte)
     *
     * @return array{periodo_inicio: string, periodo_fim: string, data_vencimento: string|null}
     */
    public function intervaloPeriodoFatura(int $mes, int $ano): array
    {
        $competencia = Carbon::create($ano, $mes, 1)->startOfMonth();

        if (empty($this->dia_limite_fatura)) {
            $dataVencimento = null;
            if (!empty($this->dia_vencimento_fatura)) {
                $dataVencimento = $competencia->copy()
                    ->day(min((int) $this->dia_vencimento_fatura, $competencia->daysInMonth))
                    ->toDateString();
            }

            return [
                'periodo_inicio' => $competencia->toDateString(),
                'periodo_fim' => $competencia->copy()->endOfMonth()->toDateString(),
                'data_vencimento' => $dataVencimento,
            ];
        }

        $limite = (int) $this->dia_limite_fatura;

        $periodoFim = $competencia->copy();
        $periodoFim->day(min($limite, $periodoFim->daysInMonth));

        $mesAnterior = $competencia->copy()->subMonthNoOverflow();
        $periodoInicio = $mesAnterior->copy()
            ->day(min($limite, $mesAnterior->daysInMonth))
            ->addDay();

        $dataVencimento = null;
        if (!empty($this->dia_vencimento_fatura)) {
            $vencimento = (int) $this->dia_vencimento_fatura;
            $vencRef = $competencia->copy();

            // Fechamento 25 / vencimento 05 → vence no mês seguinte à competência
            if ($vencimento <= $limite) {
                $vencRef->addMonthNoOverflow();
            }

            $vencRef->day(min($vencimento, $vencRef->daysInMonth));
            $dataVencimento = $vencRef->toDateString();
        }

        return [
            'periodo_inicio' => $periodoInicio->toDateString(),
            'periodo_fim' => $periodoFim->toDateString(),
            'data_vencimento' => $dataVencimento,
        ];
    }
}
