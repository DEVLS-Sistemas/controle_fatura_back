<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'id' => 'integer',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function cartoes(): HasMany
    {
        return $this->hasMany(Cartao::class, 'user_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'user_id');
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

    public function estabelecimentoCategorias(): HasMany
    {
        return $this->hasMany(EstabelecimentoCategoria::class, 'user_id');
    }
}
