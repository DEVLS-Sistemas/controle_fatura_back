<?php

namespace Tests\Unit;

use App\Enums\AnexoOrigem;
use App\Enums\AnexoStatus;
use App\Models\Anexo;
use App\Models\CompraAnexo;
use App\Models\Fatura;
use App\Models\Transacao;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

class AnexoModelTest extends TestCase
{
    public function test_origem_e_status_sao_enums(): void
    {
        $anexo = $this->anexo([
            'origem' => AnexoOrigem::Fatura->value,
            'status' => AnexoStatus::Pendente->value,
            'referencia_id' => 10,
        ]);

        $this->assertSame(AnexoOrigem::Fatura, $anexo->origem);
        $this->assertSame(AnexoStatus::Pendente, $anexo->status);
        $this->assertSame(10, $anexo->referencia_id);
    }

    public function test_referencia_de_fatura_aponta_para_fatura(): void
    {
        $anexo = $this->anexo([
            'origem' => AnexoOrigem::Fatura->value,
            'referencia_id' => 7,
        ]);

        $this->assertSame(Fatura::class, $anexo->origem->modelo());
    }

    public function test_referencia_de_compra_aponta_para_transacao(): void
    {
        $anexo = $this->anexo([
            'origem' => AnexoOrigem::Compra->value,
            'referencia_id' => 22,
        ]);

        $this->assertSame(Transacao::class, $anexo->origem->modelo());
    }

    public function test_referencia_sem_origem_retorna_null(): void
    {
        $anexo = $this->anexo([
            'origem' => null,
            'referencia_id' => 1,
        ]);

        $this->assertNull($anexo->referencia());
    }

    public function test_user_e_relacao_belongs_to(): void
    {
        $anexo = new Anexo;

        $this->assertInstanceOf(BelongsTo::class, $anexo->user());
        $this->assertSame(User::class, $anexo->user()->getRelated()::class);
    }

    public function test_fatura_tem_pdf_e_csv_opcionais_para_anexos(): void
    {
        $fatura = new Fatura;

        $this->assertContains('anexo_pdf_id', $fatura->getFillable());
        $this->assertContains('anexo_csv_id', $fatura->getFillable());
        $this->assertContains('arquivo_pdf', $fatura->getFillable());
        $this->assertContains('arquivo_csv', $fatura->getFillable());
        $this->assertInstanceOf(BelongsTo::class, $fatura->anexoPdf());
        $this->assertInstanceOf(BelongsTo::class, $fatura->anexoCsv());
        $this->assertSame(Anexo::class, $fatura->anexoPdf()->getRelated()::class);
        $this->assertSame(Anexo::class, $fatura->anexoCsv()->getRelated()::class);
    }

    public function test_fatura_tem_anexo_pelo_path_ou_pelo_catalogo(): void
    {
        $soPath = new Fatura;
        $soPath->arquivo_pdf = 'faturas/1/antigo.pdf';
        $soPath->anexo_pdf_id = null;

        $soCatalogo = new Fatura;
        $soCatalogo->arquivo_pdf = null;
        $soCatalogo->anexo_pdf_id = 15;

        $vazia = new Fatura;
        $vazia->arquivo_pdf = null;
        $vazia->anexo_pdf_id = null;

        $this->assertTrue($soPath->temPdf());
        $this->assertTrue($soCatalogo->temPdf());
        $this->assertFalse($vazia->temAnexo());
    }

    public function test_compra_anexo_aponta_para_catalogo_sem_remover_path(): void
    {
        $linha = new CompraAnexo;

        $this->assertContains('anexo_id', $linha->getFillable());
        $this->assertContains('path', $linha->getFillable());
        $this->assertInstanceOf(BelongsTo::class, $linha->anexo());
        $this->assertSame(Anexo::class, $linha->anexo()->getRelated()::class);
    }

    public function test_soft_delete_nao_define_exclusao_de_blob(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Anexo::class));
        $fonte = file_get_contents((new \ReflectionClass(Anexo::class))->getFileName());

        $this->assertStringNotContainsString('Storage::', $fonte);
        $this->assertStringNotContainsString('deleteDirectory', $fonte);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function anexo(array $attrs): Anexo
    {
        $anexo = new Anexo;
        $anexo->setRawAttributes(array_merge([
            'id' => 1,
            'user_id' => 1,
            'origem' => AnexoOrigem::Fatura->value,
            'referencia_id' => 1,
            'nome_original' => 'fatura.pdf',
            'status' => AnexoStatus::Pendente->value,
            'deleted_at' => null,
        ], $attrs), true);

        return $anexo;
    }
}
