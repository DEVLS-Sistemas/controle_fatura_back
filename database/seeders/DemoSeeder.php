<?php

namespace Database\Seeders;

use App\Models\Cartao;
use App\Models\CartaoBandeira;
use App\Models\CartaoNumero;
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
                'banco' => 'Nubank',
                'dia_limite_fatura' => 5,
                'dia_vencimento_fatura' => 12,
                'cor_fundo' => '#8b5cf6',
                'cor_texto' => '#ffffff',
                'bandeira' => 'Mastercard',
                'ultimos_digitos' => '1234',
                'limite_credito' => 8000.00,
            ],
            [
                'nome' => 'Inter Empresa',
                'banco' => 'Inter',
                'dia_limite_fatura' => 10,
                'dia_vencimento_fatura' => 17,
                'cor_fundo' => '#22c55e',
                'cor_texto' => '#052e16',
                'bandeira' => 'Visa',
                'ultimos_digitos' => '5678',
                'limite_credito' => 15000.00,
            ],
            [
                'nome' => 'Itaú Pessoal',
                'banco' => 'Itaú',
                'dia_limite_fatura' => 25,
                'dia_vencimento_fatura' => 1,
                'cor_fundo' => '#3b82f6',
                'cor_texto' => '#ffffff',
                'bandeira' => 'Visa',
                'ultimos_digitos' => '9012',
                'limite_credito' => 5000.00,
            ],
        ];

        foreach ($cartoes as $cartaoData) {
            $cartao = Cartao::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'nome' => $cartaoData['nome'],
                ],
                [
                    'banco' => $cartaoData['banco'],
                    'dia_limite_fatura' => $cartaoData['dia_limite_fatura'],
                    'dia_vencimento_fatura' => $cartaoData['dia_vencimento_fatura'],
                    'cor_fundo' => $cartaoData['cor_fundo'],
                    'cor_texto' => $cartaoData['cor_texto'],
                    'ativo' => true,
                ]
            );

            $bandeira = CartaoBandeira::updateOrCreate(
                [
                    'cartao_id' => $cartao->id,
                    'bandeira' => $cartaoData['bandeira'],
                ],
                [
                    'limite_credito' => $cartaoData['limite_credito'],
                    'ativo' => true,
                ]
            );

            CartaoNumero::updateOrCreate(
                [
                    'cartao_bandeira_id' => $bandeira->id,
                    'ultimos_digitos' => $cartaoData['ultimos_digitos'],
                ],
                [
                    'tipo' => 'fisico',
                    'ativo' => true,
                ]
            );
        }
    }
}
