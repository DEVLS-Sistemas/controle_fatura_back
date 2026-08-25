<?php

namespace App\Services\Auth;

use App\Mail\RecuperarSenhaMail;
use App\Models\Categoria;
use App\Models\PasswordResetCode;
use App\Models\Responsavel;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    public function handleRegister(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->auth = $this->register($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleLogin(object $atributes): object
    {
        try {
            $result = (object) [];
            $result->auth = $this->login($atributes);
            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleLogout(): object
    {
        try {
            $result = (object) [];
            $result->auth = $this->logout();
            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleMe(): object
    {
        try {
            $result = (object) [];
            $result->auth = $this->me();
            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleAtualizarPerfil(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->auth = $this->atualizarPerfil($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleRecuperarSenha(object $atributes): object
    {
        try {
            $result = (object) [];
            $result->auth = $this->recuperarSenha($atributes);
            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleVerificarCodigo(object $atributes): object
    {
        try {
            $result = (object) [];
            $result->auth = $this->verificarCodigo($atributes);
            return $result;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function handleRedefinirSenha(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->auth = $this->redefinirSenha($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function register(object $atributes): object
    {
        try {
            $name = trim((string) ($atributes->name ?? ''));
            $email = trim((string) ($atributes->email ?? ''));
            $password = (string) ($atributes->password ?? '');

            if ($name === '' || $email === '' || $password === '') {
                throw new Exception('Nome, e-mail e senha são obrigatórios', 422);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-mail inválido', 422);
            }

            if (strlen($password) < 6) {
                throw new Exception('A senha deve ter no mínimo 6 caracteres', 422);
            }

            if (property_exists($atributes, 'password_confirmation')
                && (string) $atributes->password_confirmation !== $password
            ) {
                throw new Exception('A confirmação da senha não confere', 422);
            }

            $exists = User::withTrashed()->where('email', $email)->exists();
            if ($exists) {
                throw new Exception('E-mail já cadastrado', 422);
            }

            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $saved = $user->save();
            if (!$saved) {
                throw new Exception('Não foi possível cadastrar o usuário', 500);
            }

            $this->seedDefaults($user);

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->sessionPayload($user->fresh(['pessoaPrincipal']), $token, 'Usuário cadastrado com sucesso!');
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function login(object $atributes): object
    {
        try {
            $email = trim((string) ($atributes->email ?? ''));
            $password = (string) ($atributes->password ?? '');
            // Contrato do front: persistir e-mail. Não altera TTL nem o payload do token.
            $this->normalizeLembrarMe($atributes->lembrar_me ?? false);

            if ($email === '' || $password === '') {
                throw new Exception('E-mail e senha são obrigatórios', 422);
            }

            $user = User::where('email', $email)->first();

            if (!$user || !Hash::check($password, $user->password)) {
                throw new Exception('Credenciais inválidas', 401);
            }

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->sessionPayload($user, $token, 'Login realizado com sucesso!');
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function me(): object
    {
        $user = Auth::user();

        if (!$user) {
            throw new Exception('Não autenticado', 401);
        }

        (new \App\Services\Pessoa\PessoaService())->ensurePrincipalForUser($user);
        $user->load('pessoaPrincipal');

        return (object) [
            'data' => [
                'user' => $user->toAuthArray(),
            ],
            'status' => true,
            'message' => 'Usuário autenticado',
        ];
    }

    public function atualizarPerfil(object $atributes): object
    {
        $user = Auth::user();

        if (!$user) {
            throw new Exception('Não autenticado', 401);
        }

        $name = trim((string) ($atributes->name ?? ''));
        $sobrenome = $this->nullableTrim($atributes->sobrenome ?? null);
        $email = trim((string) ($atributes->email ?? ''));
        $cpfCnpj = $this->normalizeCpfCnpj($atributes->cpf_cnpj ?? null);

        if ($name === '' || $email === '') {
            throw new Exception('Nome e e-mail são obrigatórios', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido', 422);
        }

        $emailEmUso = User::withTrashed()
            ->where('email', $email)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($emailEmUso) {
            throw new Exception('E-mail já cadastrado', 422);
        }

        $user->name = $name;
        $user->sobrenome = $sobrenome;
        $user->cpf_cnpj = $cpfCnpj;
        $user->email = $email;

        if (property_exists($atributes, 'renda_mensal')) {
            $user->renda_mensal = $this->parseRendaMensal($atributes->renda_mensal);
        }

        if (!$user->save()) {
            throw new Exception('Não foi possível atualizar o perfil', 500);
        }

        (new \App\Services\Pessoa\PessoaService())->syncPrincipalFromUser($user->fresh());

        return (object) [
            'data' => [
                'user' => $user->fresh(['pessoaPrincipal'])->toAuthArray(),
            ],
            'status' => true,
            'message' => 'Perfil atualizado com sucesso!',
        ];
    }

    public function logout(): object
    {
        try {
            $user = Auth::user();

            if (!$user) {
                throw new Exception('Não autenticado', 401);
            }

            $user->currentAccessToken()->delete();

            return (object) [
                'data' => [],
                'status' => true,
                'message' => 'Logout realizado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function recuperarSenha(object $atributes): object
    {
        $email = $this->normalizeEmail($atributes->email ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido', 422);
        }

        $user = $this->findUserByEmail($email);
        $resposta = $this->mensagemRecuperarSenha();

        if (!$user) {
            Hash::make($this->gerarCodigo());
            return $resposta;
        }

        $recente = PasswordResetCode::where('email', $email)
            ->where('created_at', '>=', now()->subSeconds(PasswordResetCode::THROTTLE_SEGUNDOS))
            ->exists();

        if ($recente) {
            return $resposta;
        }

        PasswordResetCode::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $codigo = $this->gerarCodigo();

        PasswordResetCode::create([
            'email' => $email,
            'codigo' => Hash::make($codigo),
            'expires_at' => now()->addMinutes(PasswordResetCode::EXPIRA_MINUTOS),
            'tentativas' => 0,
        ]);

        try {
            Mail::to($user->email)->send(new RecuperarSenhaMail(
                $codigo,
                (string) config('app.name'),
                PasswordResetCode::EXPIRA_MINUTOS,
            ));
        } catch (Exception $e) {
            Log::error('Falha ao enviar e-mail de recuperação de senha', [
                'exception' => $e->getMessage(),
            ]);
        }

        return $resposta;
    }

    public function verificarCodigo(object $atributes): object
    {
        $email = $this->normalizeEmail($atributes->email ?? '');
        $codigo = $this->normalizeCodigo($atributes->codigo ?? '');

        if ($email === '' || !preg_match('/^\d{6}$/', $codigo)) {
            throw new Exception('Informe o e-mail e o código de 6 dígitos', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido', 422);
        }

        $this->assertCodigoAtivo($email, $codigo);

        return (object) [
            'data' => [
                'email' => $email,
                'codigo_valido' => true,
            ],
            'status' => true,
            'message' => 'Código verificado',
        ];
    }

    public function redefinirSenha(object $atributes): object
    {
        $email = $this->normalizeEmail($atributes->email ?? '');
        $codigo = $this->normalizeCodigo($atributes->codigo ?? '');
        $password = (string) ($atributes->password ?? '');

        if ($email === '' || !preg_match('/^\d{6}$/', $codigo)) {
            throw new Exception('Informe o e-mail e o código de 6 dígitos', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido', 422);
        }

        if ($password === '' || strlen($password) < 6) {
            throw new Exception('A senha deve ter no mínimo 6 caracteres', 422);
        }

        if (property_exists($atributes, 'password_confirmation')
            && (string) $atributes->password_confirmation !== $password
        ) {
            throw new Exception('A confirmação da senha não confere', 422);
        }

        $record = $this->assertCodigoAtivo($email, $codigo);
        $user = $this->findUserByEmail($email);

        if (!$user) {
            throw new Exception('Código inválido ou expirado', 422);
        }

        $user->password = $password;
        if (!$user->save()) {
            throw new Exception('Não foi possível redefinir a senha', 500);
        }

        $record->used_at = now();
        $record->save();

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return $this->sessionPayload($user, $token, 'Senha redefinida com sucesso!');
    }

    private function assertCodigoAtivo(string $email, string $codigo): PasswordResetCode
    {
        $record = PasswordResetCode::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            throw new Exception('Código inválido ou expirado', 422);
        }

        if ($record->tentativas >= PasswordResetCode::MAX_TENTATIVAS) {
            $record->used_at = now();
            $record->save();
            throw new Exception('Código inválido ou expirado', 422);
        }

        if (!Hash::check($codigo, $record->codigo)) {
            $record->tentativas++;
            if ($record->tentativas >= PasswordResetCode::MAX_TENTATIVAS) {
                $record->used_at = now();
            }
            $record->save();
            throw new Exception('Código inválido ou expirado', 422);
        }

        return $record;
    }

    private function findUserByEmail(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function normalizeEmail(mixed $email): string
    {
        return mb_strtolower(trim((string) $email), 'UTF-8');
    }

    private function normalizeCodigo(mixed $codigo): string
    {
        return preg_replace('/\D/', '', (string) $codigo) ?? '';
    }

    private function gerarCodigo(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function mensagemRecuperarSenha(): object
    {
        return (object) [
            'data' => [],
            'status' => true,
            'message' => 'Se o e-mail informado estiver cadastrado, um código será enviado.',
        ];
    }

    private function normalizeLembrarMe(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Aceita "11400,00", "11.400,00", "11400.00" ou número.
     * Vazio / null → null. Zero ou negativo → 422.
     */
    public function parseRendaMensal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $parsed = round((float) $value, 2);
        } else {
            $raw = trim((string) $value);
            $raw = str_replace(['R$', ' '], '', $raw);
            $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? $raw;

            if (str_contains($raw, ',') && str_contains($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } elseif (str_contains($raw, ',')) {
                $raw = str_replace(',', '.', $raw);
            }

            if (!is_numeric($raw)) {
                throw new Exception('Renda mensal inválida', 422);
            }

            $parsed = round((float) $raw, 2);
        }

        if ($parsed <= 0) {
            throw new Exception('Renda mensal inválida', 422);
        }

        return $parsed;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeCpfCnpj(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length !== 11 && $length !== 14) {
            throw new Exception('CPF/CNPJ inválido', 422);
        }

        return $digits;
    }

    private function sessionPayload(User $user, string $token, string $message): object
    {
        $user->loadMissing('pessoaPrincipal');

        return (object) [
            'data' => [
                'user' => $user->toAuthArray(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'status' => true,
            'message' => $message,
        ];
    }

    private function seedDefaults(User $user): void
    {
        $categorias = [
            ['nome' => 'Alimentação', 'cor' => '#ef4444'],
            ['nome' => 'Transporte', 'cor' => '#3b82f6'],
            ['nome' => 'Empresa', 'cor' => '#8b5cf6'],
            ['nome' => 'Lazer', 'cor' => '#22c55e'],
            ['nome' => 'Moradia', 'cor' => '#f59e0b'],
            ['nome' => 'Saúde', 'cor' => '#ec4899'],
            ['nome' => 'Outros', 'cor' => '#6b7280'],
        ];

        foreach ($categorias as $categoria) {
            Categoria::create([
                'user_id' => $user->id,
                'nome' => $categoria['nome'],
                'cor' => $categoria['cor'],
                'ativo' => true,
            ]);
        }

        $responsaveis = [
            ['nome' => 'Eu', 'tipo' => 'pessoal'],
            ['nome' => 'Empresa', 'tipo' => 'empresa'],
        ];

        foreach ($responsaveis as $responsavel) {
            Responsavel::create([
                'user_id' => $user->id,
                'nome' => $responsavel['nome'],
                'tipo' => $responsavel['tipo'],
                'ativo' => true,
            ]);
        }

        (new \App\Services\Pessoa\PessoaService())->ensurePrincipalForUser($user);
    }
}
