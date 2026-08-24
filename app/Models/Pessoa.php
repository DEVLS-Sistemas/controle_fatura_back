<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pessoa extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'pessoas';

    protected $fillable = [
        'user_id',
        'nome',
        'sobrenome',
        'cpf_cnpj',
        'eh_principal',
        'responsavel_id',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'eh_principal' => 'boolean',
        'responsavel_id' => 'integer',
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

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Responsavel::class, 'responsavel_id');
    }

    public function cartoes(): HasMany
    {
        return $this->hasMany(Cartao::class, 'pessoa_id');
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'pessoa_id');
    }

    public function nomeCompleto(): string
    {
        return trim(implode(' ', array_filter([
            (string) $this->nome,
            (string) ($this->sobrenome ?? ''),
        ], static fn ($p) => $p !== '')));
    }

    public function toListArray(): array
    {
        return [
            'id' => (int) $this->id,
            'nome' => $this->nome,
            'sobrenome' => $this->sobrenome,
            'nome_completo' => $this->nomeCompleto(),
            'cpf_cnpj' => $this->cpf_cnpj,
            'eh_principal' => (bool) $this->eh_principal,
            'responsavel_id' => $this->responsavel_id !== null ? (int) $this->responsavel_id : null,
            'ativo' => (bool) $this->ativo,
        ];
    }
}
