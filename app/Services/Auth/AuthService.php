<?php

namespace App\Services\Auth;

use App\Models\Categoria;
use App\Models\Responsavel;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

            return $this->sessionPayload($user, $token, 'Usuário cadastrado com sucesso!');
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function login(object $atributes): object
    {
        try {
            $email = trim((string) ($atributes->email ?? ''));
            $password = (string) ($atributes->password ?? '');

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

        return (object) [
            'data' => [
                'user' => $user->toAuthArray(),
            ],
            'status' => true,
            'message' => 'Usuário autenticado',
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

    private function sessionPayload(User $user, string $token, string $message): object
    {
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
    }
}
