<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Repasse extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'repasses';

    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_PARCIAL = 'parcial';
    public const STATUS_PAGO = 'pago';

    public const STATUS = [
        self::STATUS_PENDENTE,
        self::STATUS_PARCIAL,
        self::STATUS_PAGO,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDENTE => 'Pendente',
        self::STATUS_PARCIAL => 'Parcial',
        self::STATUS_PAGO => 'Pago',
    ];

    protected $fillable = [
        'user_id',
        'transacao_id',
        'valor',
        'data_pagamento',
        'observacoes',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'transacao_id' => 'integer',
        'valor' => 'decimal:2',
        'data_pagamento' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transacao(): BelongsTo
    {
        return $this->belongsTo(Transacao::class, 'transacao_id');
    }
}
