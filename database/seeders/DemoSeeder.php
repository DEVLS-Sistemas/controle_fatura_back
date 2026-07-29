<?php

namespace Database\Seeders;

use App\Models\Cartao;
use App\Models\Categoria;
use App\Models\Responsavel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de demonstração:
 * - Usuário demo@demo.com / 123456
 * - Categorias e responsáveis padrão
 * - Cartões de exemplo
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@demo.com'],
            [
                'name' => 'Usuário Demo',
                'password' => Hash::make('123456'),
            ]
        );

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
            Categoria::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'nome' => $categoria['nome'],
                ],
                [
                    'cor' => $categoria['cor'],
                    'ativo' => true,
                ]
            );
        }

        $responsaveis = [
            ['nome' => 'Eu', 'tipo' => 'pessoal'],
            ['nome' => 'Empresa', 'tipo' => 'empresa'],
            ['nome' => 'Família', 'tipo' => 'pessoal'],
        ];

        foreach ($responsaveis as $responsavel) {
            Responsavel::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'nome' => $responsavel['nome'],
                ],
                [
                    'tipo' => $responsavel['tipo'],
                    'ativo' => true,
                ]
            );
        }

        $cartoes = [
            [
                'nome' => 'Nubank Principal',
                'bandeira' => 'Mastercard',
                'banco' => 'Nubank',
                'ultimos_digitos' => '1234',
            ],
            [
                'nome' => 'Inter Empresa',
                'bandeira' => 'Visa',
                'banco' => 'Inter',
                'ultimos_digitos' => '5678',
            ],
            [
                'nome' => 'Itaú Pessoal',
                'bandeira' => 'Visa',
                'banco' => 'Itaú',
                'ultimos_digitos' => '9012',
            ],
        ];

        foreach ($cartoes as $cartao) {
            Cartao::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'nome' => $cartao['nome'],
                ],
                [
                    'bandeira' => $cartao['bandeira'],
                    'banco' => $cartao['banco'],
                    'ultimos_digitos' => $cartao['ultimos_digitos'],
                    'ativo' => true,
                ]
            );
        }
    }
}
