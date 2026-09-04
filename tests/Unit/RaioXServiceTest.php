<?php

namespace Tests\Unit;

use App\Services\Auth\AuthService;
use App\Services\Dashboard\RaioXService;
use Carbon\Carbon;
use Exception;
use PHPUnit\Framework\TestCase;

class RaioXServiceTest extends TestCase
{
    private RaioXService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RaioXService();
    }

    public function test_format_brl_sem_centavos_e_com_milhar(): void
    {
        $this->assertSame('R$ 8.420', $this->service->formatBrl(8420.0));
        $this->assertSame('R$ 8.420,50', $this->service->formatBrl(8420.5));
        $this->assertSame('R$ 0', $this->service->formatBrl(0));
    }

    public function test_frases_do_exemplo_de_produto(): void
    {
        $this->assertSame(
            'Você possui R$ 8.420 em parcelas futuras, distribuídas em 23 compras.',
            $this->service->fraseParceladas(8420.0, 23)
        );
        $this->assertSame(
            '74% da sua renda já está comprometida',
            $this->service->fraseComprometimento(74.0)
        );
        $this->assertSame(
            'Faturas cresceram 18%',
            $this->service->fraseCrescimento(18.0, 4820.0)
        );
        $this->assertSame(
            'Se não realizar novas compras parceladas, seu comprometimento deve cair para 51% em janeiro.',
            $this->service->fraseProjecaoParceladas([
                'percentual' => 51.0,
                'mes' => 1,
                'ano' => 2027,
                'label' => 'janeiro',
            ], 11400.0)
        );
    }

    public function test_frase_parceladas_singular(): void
    {
        $this->assertSame(
            'Você possui R$ 200 em parcelas futuras, distribuída em 1 compra.',
            $this->service->fraseParceladas(200.0, 1)
        );
    }

    public function test_niveis_crescimento_e_comprometimento(): void
    {
        $this->assertSame(RaioXService::NIVEL_POSITIVO, $this->service->nivelCrescimento(null));
        $this->assertSame(RaioXService::NIVEL_POSITIVO, $this->service->nivelCrescimento(-5.0));
        $this->assertSame(RaioXService::NIVEL_ATENCAO, $this->service->nivelCrescimento(18.0));
        $this->assertSame(RaioXService::NIVEL_ALERTA, $this->service->nivelCrescimento(21.0));

        $this->assertSame(RaioXService::NIVEL_POSITIVO, $this->service->nivelComprometimento(29.9));
        $this->assertSame(RaioXService::NIVEL_ATENCAO, $this->service->nivelComprometimento(30.0));
        $this->assertSame(RaioXService::NIVEL_ALERTA, $this->service->nivelComprometimento(74.0));
    }

    public function test_variacao_percentual_sem_base_e_nula(): void
    {
        $this->assertNull($this->service->variacaoPercentual(4800.0, 0.0, false));
        $this->assertNull($this->service->variacaoPercentual(4800.0, 0.0, true));
        $this->assertSame(18.0, $this->service->variacaoPercentual(4820.0, 4085.0, true));
    }

    public function test_classificar_pagamentos_em_dia_atraso_e_a_vencer(): void
    {
        $hoje = Carbon::create(2026, 8, 25);

        $emDia = $this->service->classificarPagamentos([
            ['pago' => true, 'valor_total' => 1000, 'valor_restante' => 0, 'data_vencimento' => '2026-08-12'],
            ['pago' => false, 'valor_total' => 0, 'valor_restante' => 0, 'data_vencimento' => '2026-08-01'],
        ], $hoje);
        $this->assertSame(RaioXService::NIVEL_POSITIVO, $emDia['nivel']);

        $atraso = $this->service->classificarPagamentos([
            ['pago' => false, 'valor_total' => 500, 'valor_restante' => 500, 'data_vencimento' => '2026-08-20'],
        ], $hoje);
        $this->assertSame(RaioXService::NIVEL_ALERTA, $atraso['nivel']);
        $this->assertCount(1, $atraso['atrasadas']);

        $aVencer = $this->service->classificarPagamentos([
            ['pago' => false, 'valor_total' => 800, 'valor_restante' => 800, 'data_vencimento' => '2026-08-28'],
        ], $hoje);
        $this->assertSame(RaioXService::NIVEL_ATENCAO, $aVencer['nivel']);
        $this->assertCount(1, $aVencer['a_vencer']);
    }

    public function test_fatura_com_pdf_aguarda_confirmacao_enquanto_mes_seguinte_nao_tem_anexo(): void
    {
        $hoje = Carbon::create(2026, 9, 4);
        $fatura = [
            'pago' => false,
            'valor_total' => 7512.20,
            'valor_restante' => 7512.20,
            'data_vencimento' => '2026-08-10',
            'mes' => 8,
            'ano' => 2026,
            'proxima_tem_anexo' => false,
        ];

        $classificado = $this->service->classificarPagamentos([$fatura], $hoje);
        $this->assertSame(RaioXService::NIVEL_ATENCAO, $classificado['nivel']);
        $this->assertCount(0, $classificado['atrasadas']);
        $this->assertCount(1, $classificado['aguardando_confirmacao']);
        $this->assertFalse($this->service->atrasoConfirmado($fatura, $hoje));

        $sinal = $this->service->montarSinalPagamentos($classificado, 8, 2026);
        $this->assertSame(RaioXService::NIVEL_ATENCAO, $sinal['nivel']);
        $this->assertSame('Aguardando confirmação de pagamento', $sinal['frase']);
        $this->assertStringContainsString('agosto', $sinal['contexto']);
        $this->assertStringContainsString('anexo da fatura seguinte', $sinal['contexto']);
        $this->assertStringContainsString('operação manual', $sinal['contexto']);
        $this->assertSame(1, $sinal['metricas']['aguardando_confirmacao']);
        $this->assertSame(0, $sinal['metricas']['atrasadas']);
    }

    public function test_salto_de_dois_meses_confirma_atraso_sem_pdf_do_mes_seguinte(): void
    {
        $hoje = Carbon::create(2026, 10, 4);
        $fatura = [
            'pago' => false,
            'valor_total' => 7512.20,
            'valor_restante' => 7512.20,
            'data_vencimento' => '2026-08-10',
            'mes' => 8,
            'ano' => 2026,
            'proxima_tem_anexo' => false,
        ];

        $this->assertTrue($this->service->atrasoConfirmado($fatura, $hoje));

        $classificado = $this->service->classificarPagamentos([$fatura], $hoje);
        $this->assertSame(RaioXService::NIVEL_ALERTA, $classificado['nivel']);
        $this->assertCount(1, $classificado['atrasadas']);
        $this->assertCount(0, $classificado['aguardando_confirmacao']);
    }

    public function test_pdf_do_mes_seguinte_sem_pagamento_confirma_atraso(): void
    {
        $hoje = Carbon::create(2026, 9, 4);
        $fatura = [
            'pago' => false,
            'valor_total' => 7512.20,
            'valor_restante' => 7512.20,
            'data_vencimento' => '2026-08-10',
            'mes' => 8,
            'ano' => 2026,
            'proxima_tem_anexo' => true,
        ];

        $classificado = $this->service->classificarPagamentos([$fatura], $hoje);
        $this->assertSame(RaioXService::NIVEL_ALERTA, $classificado['nivel']);
        $this->assertCount(1, $classificado['atrasadas']);
        $this->assertCount(0, $classificado['aguardando_confirmacao']);
    }

    public function test_prioridade_do_diagnostico(): void
    {
        $this->assertSame(
            RaioXService::TIPO_ATRASO,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_ALERTA,
                23,
                8420.0,
                8430.0,
                200.0,
                RaioXService::NIVEL_ALERTA,
                ['percentual' => 50, 'valor' => 4000]
            )
        );

        $this->assertSame(
            RaioXService::TIPO_PARCELADAS,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_POSITIVO,
                23,
                8420.0,
                8430.0,
                200.0,
                RaioXService::NIVEL_ALERTA,
                ['percentual' => 50, 'valor' => 4000]
            )
        );

        $this->assertSame(
            RaioXService::TIPO_ASSINATURAS,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_POSITIVO,
                0,
                0.0,
                1000.0,
                200.0,
                RaioXService::NIVEL_ALERTA,
                null
            )
        );

        $this->assertSame(
            RaioXService::TIPO_CRESCIMENTO,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_POSITIVO,
                0,
                0.0,
                1000.0,
                0.0,
                RaioXService::NIVEL_ALERTA,
                null
            )
        );

        $this->assertSame(
            RaioXService::TIPO_CONCENTRACAO,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_POSITIVO,
                0,
                0.0,
                1000.0,
                0.0,
                RaioXService::NIVEL_ATENCAO,
                ['percentual' => 45.0, 'valor' => 450.0]
            )
        );

        $this->assertSame(
            RaioXService::TIPO_OK,
            $this->service->escolherTipoDiagnostico(
                RaioXService::NIVEL_POSITIVO,
                0,
                0.0,
                1000.0,
                0.0,
                RaioXService::NIVEL_POSITIVO,
                null
            )
        );
    }

    public function test_horizonte_projecao_encontra_janeiro(): void
    {
        $serie = [
            ['mes' => 7, 'ano' => 2026, 'label' => 'Jul/2026', 'referencia' => false, 'total' => 4000],
            ['mes' => 8, 'ano' => 2026, 'label' => 'Ago/2026', 'referencia' => true, 'total' => 8430],
            ['mes' => 9, 'ano' => 2026, 'label' => 'Set/2026', 'referencia' => false, 'total' => 8400],
            ['mes' => 10, 'ano' => 2026, 'label' => 'Out/2026', 'referencia' => false, 'total' => 8300],
            ['mes' => 11, 'ano' => 2026, 'label' => 'Nov/2026', 'referencia' => false, 'total' => 8200],
            ['mes' => 12, 'ano' => 2026, 'label' => 'Dez/2026', 'referencia' => false, 'total' => 8100],
            ['mes' => 1, 'ano' => 2027, 'label' => 'Jan/2027', 'referencia' => false, 'total' => 5814],
        ];

        $horizonte = $this->service->encontrarHorizonteProjecao($serie, 74.0, 11400.0);

        $this->assertNotNull($horizonte);
        $this->assertSame(1, $horizonte['mes']);
        $this->assertSame(2027, $horizonte['ano']);
        $this->assertSame('janeiro', $horizonte['label']);
        $this->assertSame(51.0, $horizonte['percentual']);
    }

    public function test_horizonte_projecao_sem_queda_relevante_retorna_null(): void
    {
        $serie = [
            ['mes' => 8, 'ano' => 2026, 'label' => 'Ago/2026', 'referencia' => true, 'total' => 8430],
            ['mes' => 9, 'ano' => 2026, 'label' => 'Set/2026', 'referencia' => false, 'total' => 8400],
            ['mes' => 10, 'ano' => 2026, 'label' => 'Out/2026', 'referencia' => false, 'total' => 8380],
        ];

        $this->assertNull($this->service->encontrarHorizonteProjecao($serie, 74.0, 11400.0));
        $this->assertSame(
            'Mesmo sem novas parceladas, o comprometimento segue alto nos próximos 12 meses.',
            $this->service->fraseProjecaoParceladas(null, 11400.0)
        );
        $this->assertNull($this->service->fraseProjecaoParceladas(null, null));
    }

    public function test_sinal_comprometimento_sem_renda(): void
    {
        $sinal = $this->service->montarSinalComprometimento(8430.0, null, 8, 2026);

        $this->assertSame('comprometimento', $sinal['id']);
        $this->assertSame(RaioXService::NIVEL_INCOMPLETO, $sinal['nivel']);
        $this->assertSame('Informe sua renda para ver o comprometimento', $sinal['frase']);
        $this->assertSame('perfil', $sinal['atalho']['rota']);
    }

    public function test_acoes_priorizam_o_tipo_e_limitam_a_tres(): void
    {
        $acoes = $this->service->montarAcoes(RaioXService::TIPO_PARCELADAS, 8, 2026);

        $this->assertCount(3, $acoes);
        $this->assertSame('parceladas', $acoes[0]['id']);
        $this->assertSame('posso_comprar', $acoes[1]['id']);
        $this->assertSame('gastos_criticos', $acoes[2]['id']);
    }

    public function test_parse_renda_mensal(): void
    {
        $auth = new AuthService();

        $this->assertSame(11400.0, $auth->parseRendaMensal('11.400,00'));
        $this->assertSame(11400.0, $auth->parseRendaMensal('11400,00'));
        $this->assertSame(11400.5, $auth->parseRendaMensal(11400.5));
        $this->assertNull($auth->parseRendaMensal(null));
        $this->assertNull($auth->parseRendaMensal(''));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Renda mensal inválida');
        $auth->parseRendaMensal(0);
    }
}
