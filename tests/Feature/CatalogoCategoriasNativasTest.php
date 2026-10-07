<?php

namespace Tests\Feature;

use App\Models\Cartao;
use App\Models\Categoria;
use App\Models\Estabelecimento;
use App\Models\Fatura;
use App\Models\Responsavel;
use App\Models\Subcategoria;
use App\Models\Transacao;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Categoria\CatalogoCategoriasNativas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogoCategoriasNativasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cadastro_recebe_catalogo_com_cores_e_subcategoria_compartilhada(): void
    {
        $email = 'catalogo-'.uniqid('', true).'@test.local';

        (new AuthService())->register((object) [
            'name' => 'Catalogo',
            'email' => $email,
            'password' => '123456',
        ]);

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(20, Categoria::where('user_id', $user->id)->count());

        $alimentacao = $this->categoria($user->id, 'Alimentação');
        $this->assertSame('#4caf50', $alimentacao->cor);
        $this->assertTrue($alimentacao->ativo);

        $supermercado = $this->subcategoria($user->id, 'Supermercado');
        $this->assertSame(
            '#81c784',
            $this->corDoVinculo($alimentacao->id, $supermercado->id)
        );

        $outros = Subcategoria::where('user_id', $user->id)
            ->whereRaw('LOWER(nome) = ?', ['outros'])
            ->get();
        $this->assertCount(1, $outros);

        $cores = DB::table('categoria_subcategoria')
            ->where('subcategoria_id', $outros->first()->id)
            ->pluck('cor')
            ->unique();
        $this->assertGreaterThan(1, $cores->count());

        $categoriasAntes = Categoria::where('user_id', $user->id)->count();
        $subsAntes = Subcategoria::where('user_id', $user->id)->count();
        $vinculosAntes = $this->vinculosDoUser($user->id);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $this->assertSame($categoriasAntes, Categoria::where('user_id', $user->id)->count());
        $this->assertSame($subsAntes, Subcategoria::where('user_id', $user->id)->count());
        $this->assertSame($vinculosAntes, $this->vinculosDoUser($user->id));
    }

    public function test_reaproveita_categoria_existente_sem_sobrescrever_edicao(): void
    {
        $user = $this->user();
        $alimentacao = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'alimentação',
            'cor' => '#111111',
            'ativo' => false,
        ]);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $alimentacao->refresh();
        $this->assertSame('#111111', $alimentacao->cor);
        $this->assertFalse($alimentacao->ativo);
        $this->assertSame(
            1,
            Categoria::withTrashed()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(nome) = ?', ['alimentação'])
                ->count()
        );
        $this->assertSame(6, $alimentacao->subcategorias()->count());
        $this->assertSame(20, Categoria::where('user_id', $user->id)->count());
    }

    public function test_categoria_antiga_com_nome_de_subcategoria_deixa_de_ser_categoria(): void
    {
        $user = $this->user();
        $acougue = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Açougue',
            'cor' => '#f59e0b',
            'ativo' => true,
        ]);
        $cartao = Cartao::create([
            'user_id' => $user->id,
            'nome' => 'Nubank',
            'banco' => 'Nubank',
            'ativo' => true,
            'dia_limite_fatura' => 5,
            'dia_vencimento_fatura' => 10,
        ]);
        $fatura = Fatura::create([
            'user_id' => $user->id,
            'cartao_id' => $cartao->id,
            'mes' => 10,
            'ano' => 2026,
            'valor_total' => 0,
            'status' => 'pendente',
        ]);
        $estabelecimento = Estabelecimento::create([
            'user_id' => $user->id,
            'nome' => 'Açougue do Bairro',
            'ativo' => true,
            'categoria_padrao_id' => $acougue->id,
        ]);
        $responsavel = Responsavel::create([
            'user_id' => $user->id,
            'nome' => 'Eu',
            'tipo' => 'pessoal',
            'ativo' => true,
        ]);
        $compra = Transacao::create([
            'user_id' => $user->id,
            'fatura_id' => $fatura->id,
            'estabelecimento_id' => $estabelecimento->id,
            'responsavel_id' => $responsavel->id,
            'categoria_id' => $acougue->id,
            'data' => '2026-10-01',
            'valor' => 40,
            'tipo' => Transacao::TIPO_PURCHASE,
            'compra_manual' => true,
            'importada_pdf' => false,
        ]);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $this->assertSoftDeleted('categorias', ['id' => $acougue->id]);
        $this->assertSame(
            0,
            Categoria::where('user_id', $user->id)
                ->whereRaw('LOWER(nome) = ?', ['açougue'])
                ->count()
        );

        $alimentacao = $this->categoria($user->id, 'Alimentação');
        $sub = $this->subcategoria($user->id, 'Açougue');
        $compra->refresh();
        $estabelecimento->refresh();

        $this->assertSame($alimentacao->id, $compra->categoria_id);
        $this->assertSame($sub->id, $compra->subcategoria_id);
        $this->assertSame($alimentacao->id, $estabelecimento->categoria_padrao_id);
        $this->assertSame($sub->id, $estabelecimento->subcategoria_padrao_id);
    }

    public function test_reparo_recoloca_categoria_do_catalogo_que_so_existia_excluida(): void
    {
        $user = $this->user();

        foreach ([
            ['nome' => 'Alimentação', 'cor' => '#ef4444'],
            ['nome' => 'Transporte', 'cor' => '#3b82f6'],
            ['nome' => 'Empresa', 'cor' => '#8b5cf6'],
            ['nome' => 'Lazer', 'cor' => '#22c55e'],
            ['nome' => 'Moradia', 'cor' => '#f59e0b'],
            ['nome' => 'Saúde', 'cor' => '#ec4899'],
            ['nome' => 'Outros', 'cor' => '#6b7280'],
        ] as $categoria) {
            Categoria::create([
                'user_id' => $user->id,
                'nome' => $categoria['nome'],
                'cor' => $categoria['cor'],
                'ativo' => $categoria['nome'] !== 'Saúde',
            ]);
        }

        foreach (['Alimentação', 'Transporte', 'Saúde', 'Outros', 'Empresa', 'Lazer', 'Moradia'] as $nome) {
            Categoria::where('user_id', $user->id)
                ->where('nome', $nome)
                ->firstOrFail()
                ->delete();
        }

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        foreach (CatalogoCategoriasNativas::categorias() as $item) {
            $categoria = $this->categoria($user->id, $item['nome']);
            $this->assertSame($item['nome'] !== 'Saúde', $categoria->ativo);
            $this->assertGreaterThan(0, $categoria->subcategorias()->count());
        }

        $alimentacao = $this->categoria($user->id, 'Alimentação');
        $this->assertSame('#ef4444', $alimentacao->cor);
        $this->assertSame(20, Categoria::where('user_id', $user->id)->count());
        $this->assertSame(
            0,
            Categoria::where('user_id', $user->id)->where('nome', 'Empresa')->count()
        );

        $antes = Categoria::where('user_id', $user->id)->count();
        CatalogoCategoriasNativas::garantirCategoriasAusentes((int) $user->id);
        $this->assertSame($antes, Categoria::where('user_id', $user->id)->count());
    }

    public function test_subcategoria_compartilhada_deixa_de_ser_categoria(): void
    {
        $user = $this->user();
        $celular = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Celular',
            'cor' => '#111111',
            'ativo' => true,
        ]);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $this->assertSoftDeleted('categorias', ['id' => $celular->id]);
        $this->assertSame(
            0,
            Categoria::where('user_id', $user->id)
                ->whereRaw('LOWER(nome) = ?', ['celular'])
                ->count()
        );

        $eletronicos = $this->categoria($user->id, 'Eletrônicos e Tecnologia');
        $sub = $this->subcategoria($user->id, 'Celular');
        $this->assertTrue(
            $eletronicos->subcategorias()->where('subcategorias.id', $sub->id)->exists()
        );
    }

    public function test_rebaixa_subcategoria_que_estava_excluida_e_pai_excluido(): void
    {
        $user = $this->user();
        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $alimentacao = $this->categoria($user->id, 'Alimentação');
        $supermercado = $this->subcategoria($user->id, 'Supermercado');
        $alimentacao->delete();
        $supermercado->delete();

        $comoCategoria = Categoria::create([
            'user_id' => $user->id,
            'nome' => 'Supermercado',
            'cor' => '#f59e0b',
            'ativo' => true,
        ]);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $this->assertSoftDeleted('categorias', ['id' => $comoCategoria->id]);
        $this->assertNotSoftDeleted('subcategorias', ['id' => $supermercado->id]);
        $this->assertNotSoftDeleted('categorias', ['id' => $alimentacao->id]);
        $this->assertSame(
            0,
            Categoria::where('user_id', $user->id)
                ->whereRaw('LOWER(nome) = ?', ['supermercado'])
                ->count()
        );
    }

    public function test_mantem_cor_do_vinculo_e_recoloca_categoria_excluida_do_catalogo(): void
    {
        $user = $this->user();
        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $pix = $this->categoria($user->id, 'Pix no Cartão');
        $pix->delete();

        $alimentacao = $this->categoria($user->id, 'Alimentação');
        $supermercado = $this->subcategoria($user->id, 'Supermercado');
        DB::table('categoria_subcategoria')
            ->where('categoria_id', $alimentacao->id)
            ->where('subcategoria_id', $supermercado->id)
            ->update(['cor' => '#abcdef']);

        CatalogoCategoriasNativas::aplicarParaUser((int) $user->id);

        $this->assertNotSoftDeleted('categorias', ['id' => $pix->id]);
        $this->assertSame(
            1,
            Categoria::where('user_id', $user->id)
                ->whereRaw('LOWER(nome) = ?', ['pix no cartão'])
                ->count()
        );
        $this->assertSame(
            '#abcdef',
            $this->corDoVinculo($alimentacao->id, $supermercado->id)
        );
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Catalogo',
            'email' => 'catalogo-'.uniqid('', true).'@test.local',
            'password' => 'password',
        ]);
    }

    private function categoria(int $userId, string $nome): Categoria
    {
        return Categoria::where('user_id', $userId)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
            ->firstOrFail();
    }

    private function subcategoria(int $userId, string $nome): Subcategoria
    {
        return Subcategoria::where('user_id', $userId)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome, 'UTF-8')])
            ->firstOrFail();
    }

    private function corDoVinculo(int $categoriaId, int $subcategoriaId): string
    {
        return (string) DB::table('categoria_subcategoria')
            ->where('categoria_id', $categoriaId)
            ->where('subcategoria_id', $subcategoriaId)
            ->value('cor');
    }

    private function vinculosDoUser(int $userId): int
    {
        return DB::table('categoria_subcategoria')
            ->whereIn('categoria_id', Categoria::where('user_id', $userId)->pluck('id'))
            ->count();
    }
}
