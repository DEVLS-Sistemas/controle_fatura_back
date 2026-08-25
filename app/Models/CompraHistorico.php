<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraHistorico extends Model
{
    use BelongsToUser, HasFactory;

    protected $table = 'compra_historicos';

    public const ACAO_CRIADA = 'criada';
    public const ACAO_EDITADA = 'editada';
    public const ACAO_CONCILIADA = 'conciliada';
    public const ACAO_DESVINCULADA = 'desvinculada';
    public const ACAO_PENDENTE = 'conciliacao_pendente';
    public const ACAO_REJEITADA = 'conciliacao_rejeitada';
    public const ACAO_ANEXO_ADICIONADO = 'anexo_adicionado';
    public const ACAO_ANEXO_REMOVIDO = 'anexo_removido';
    public const ACAO_EXCLUIDA = 'excluida';

    protected $fillable = [
        'user_id',
        'transacao_id',
        'compra_grupo_id',
        'acao',
        'descricao',
        'payload',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'transacao_id' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transacao(): BelongsTo
    {
        return $this->belongsTo(Transacao::class, 'transacao_id');
    }
}
