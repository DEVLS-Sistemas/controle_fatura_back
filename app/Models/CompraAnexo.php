<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompraAnexo extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $table = 'compra_anexos';

    public const TIPO_NOTA_FISCAL = 'nota_fiscal';
    public const TIPO_COMPROVANTE = 'comprovante';
    public const TIPO_RECIBO = 'recibo';
    public const TIPO_PRINT = 'print';
    public const TIPO_PDF = 'pdf';
    public const TIPO_IMAGEM = 'imagem';
    public const TIPO_OUTRO = 'outro';

    public const TIPOS = [
        self::TIPO_NOTA_FISCAL,
        self::TIPO_COMPROVANTE,
        self::TIPO_RECIBO,
        self::TIPO_PRINT,
        self::TIPO_PDF,
        self::TIPO_IMAGEM,
        self::TIPO_OUTRO,
    ];

    public const TIPOS_LABELS = [
        self::TIPO_NOTA_FISCAL => 'Nota fiscal',
        self::TIPO_COMPROVANTE => 'Comprovante',
        self::TIPO_RECIBO => 'Recibo',
        self::TIPO_PRINT => 'Print',
        self::TIPO_PDF => 'PDF',
        self::TIPO_IMAGEM => 'Imagem',
        self::TIPO_OUTRO => 'Outro',
    ];

    protected $fillable = [
        'user_id',
        'transacao_id',
        'compra_grupo_id',
        'nome_original',
        'path',
        'mime',
        'tamanho',
        'tipo',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'transacao_id' => 'integer',
        'tamanho' => 'integer',
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
