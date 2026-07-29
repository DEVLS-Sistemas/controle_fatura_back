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
            $user = Auth::user();

            if (!$user) {
                throw new Exception('Não autenticado', 401);
            }

            return (object) [
                'data' => $user,
                'status' => true,
                'message' => 'Usuário autenticado',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function register(object $atributes): object
    {
        try {
            if (empty($atributes->name) || empty($atributes->email) || empty($atributes->password)) {
                throw new Exception('Nome, e-mail e senha são obrigatórios', 422);
            }

            if (!filter_var($atributes->email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-mail inválido', 422);
            }

            if (strlen($atributes->password) < 6) {
                throw new Exception('A senha deve ter no mínimo 6 caracteres', 422);
            }

            $exists = User::where('email', $atributes->email)->exists();
            if ($exists) {
                throw new Exception('E-mail já cadastrado', 422);
            }

            $user = new User([
                'name' => $atributes->name,
                'email' => $atributes->email,
                'password' => $atributes->password,
            ]);

            $saved = $user->save();
            if (!$saved) {
                throw new Exception('Não foi possível cadastrar o usuário', 500);
            }

            $this->seedDefaults($user);

            $token = $user->createToken('api-token')->plainTextToken;

            return (object) [
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
                'status' => true,
                'message' => 'Usuário cadastrado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function login(object $atributes): object
    {
        try {
            if (empty($atributes->email) || empty($atributes->password)) {
                throw new Exception('E-mail e senha são obrigatórios', 422);
            }

            $user = User::where('email', $atributes->email)->first();

            if (!$user || !Hash::check($atributes->password, $user->password)) {
                throw new Exception('Credenciais inválidas', 401);
            }

            $token = $user->createToken('api-token')->plainTextToken;

            return (object) [
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
                'status' => true,
                'message' => 'Login realizado com sucesso!',
            ];
        } catch (Exception $e) {
            throw $e;
        }
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
