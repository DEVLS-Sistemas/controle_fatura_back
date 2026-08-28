<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plataforma extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'plataformas';

    /**
     * Cadastro inicial por usuário (registro + backfill de contas existentes).
     *
     * @var array<int, array{nome: string, cor: string}>
     */
    public const PADROES = [
        ['nome' => 'Loja Física', 'cor' => '#22c55e'],
        ['nome' => 'Mercado Livre', 'cor' => '#fbbc04'],
        ['nome' => 'Shopee', 'cor' => '#ee4d2d'],
        ['nome' => 'Amazon', 'cor' => '#ff9900'],
        ['nome' => 'AliExpress', 'cor' => '#e62e04'],
        ['nome' => 'iFood', 'cor' => '#ea1d2c'],
        ['nome' => 'Magalu', 'cor' => '#0086ff'],
        ['nome' => 'Shein', 'cor' => '#111827'],
        ['nome' => 'Site da loja', 'cor' => '#3b82f6'],
        ['nome' => 'Outros', 'cor' => '#6b7280'],
    ];

    protected $fillable = [
        'user_id',
        'nome',
        'cor',
        'ativo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
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

    public function transacoes(): HasMany
    {
        return $this->hasMany(Transacao::class, 'plataforma_id');
    }

    public function estabelecimentos(): HasMany
    {
        return $this->hasMany(Estabelecimento::class, 'plataforma_padrao_id');
    }

    /**
     * Cria as plataformas padrão que ainda não existem para o usuário.
     * Match case-insensitive; não restaura soft-deleted com o mesmo nome.
     */
    public static function seedPadroesParaUser(int $userId): int
    {
        $criadas = 0;

        foreach (self::PADROES as $padrao) {
            $nome = $padrao['nome'];
            $exists = self::withTrashed()
                ->where('user_id', $userId)
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
                ->exists();

            if ($exists) {
                continue;
            }

            self::create([
                'user_id' => $userId,
                'nome' => $nome,
                'cor' => $padrao['cor'],
                'ativo' => true,
            ]);
            $criadas++;
        }

        return $criadas;
    }
}
