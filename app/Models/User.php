<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'sobrenome',
        'cpf_cnpj',
        'renda_mensal',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'deleted_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'renda_mensal' => 'decimal:2',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function toAuthArray(): array
    {
        $pessoaId = $this->pessoaPrincipal?->id;

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'sobrenome' => $this->sobrenome,
            'cpf_cnpj' => $this->cpf_cnpj,
            'renda_mensal' => $this->renda_mensal !== null ? round((float) $this->renda_mensal, 2) : null,
            'email' => $this->email,
            'pessoa_id' => $pessoaId !== null ? (int) $pessoaId : null,
        ];
    }

    public function pessoaPrincipal(): HasOne
    {
        return $this->hasOne(Pessoa::class, 'user_id')->where('eh_principal', true);
    }

    public function pessoas(): HasMany
    {
        return $this->hasMany(Pessoa::class, 'user_id');
    }

    public function cartoes(): HasMany
    {
        return $this->hasMany(Cartao::class, 'user_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'user_id');
    }

    public function subcategorias(): HasMany
    {
        return $this->hasMany(Subcategoria::class, 'user_id');
    }

    public function estabelecimentos(): HasMany
    {
        return $this->hasMany(Estabelecimento::class, 'user_id');
    }

    public function responsaveis(): HasMany
    {
        return $this->hasMany(Responsavel::class, 'user_id');
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'user_id');
    }

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'user_id');
    }
}
