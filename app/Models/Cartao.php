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
        'bandeira',
        'banco',
        'ultimos_digitos',
        'dia_limite_fatura',
        'dia_vencimento_fatura',
        'cor_fundo',
        'cor_texto',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dia_limite_fatura' => 'integer',
        'dia_vencimento_fatura' => 'integer',
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
}
