<?php

namespace App\Models;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Anexo extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'anexos';

    protected $fillable = [
        'user_id',
        'origem',
        'referencia_id',
        'nome_original',
        'mime',
        'extensao',
        'tamanho_bytes',
        'hash',
        'url',
        'container',
        'blob_path',
        'disk',
        'status',
        'erro_mensagem',
        'enviado_em',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'origem' => AnexoOrigem::class,
        'referencia_id' => 'integer',
        'tamanho_bytes' => 'integer',
        'status' => AnexoStatus::class,
        'enviado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Dono na tela de origem (fatura ou compra/transação).
     * Soft delete deste catálogo não remove o blob.
     */
    public function referencia(): ?Model
    {
        if (! $this->origem instanceof AnexoOrigem || $this->referencia_id === null) {
            return null;
        }

        return $this->origem->modelo()::query()->find($this->referencia_id);
    }
}
