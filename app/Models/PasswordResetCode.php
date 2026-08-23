<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    public const EXPIRA_MINUTOS = 15;
    public const THROTTLE_SEGUNDOS = 60;
    public const MAX_TENTATIVAS = 5;

    protected $table = 'password_reset_codes';

    protected $fillable = [
        'email',
        'codigo',
        'expires_at',
        'tentativas',
        'used_at',
    ];

    protected $hidden = [
        'codigo',
    ];

    protected $casts = [
        'id' => 'integer',
        'tentativas' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isAtivo(): bool
    {
        return $this->used_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
