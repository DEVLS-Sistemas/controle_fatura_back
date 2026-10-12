<?php

namespace Tests\Unit;

use App\Models\Transacao;
use App\Services\Dashboard\ProjecaoFaturasService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ProjecaoFaturasServiceTest extends TestCase
{
    private ProjecaoFaturasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjecaoFaturasService();
    }

    public function test_build_colunas_comeca_no_mes_anterior(): void
    {
        $colunas = $this->service->buildColunas(Carbon::create(2026, 7, 1));

        $this->assertCount(13, $colunas);
        $this->assertSame('2026-06', $colunas[0]['chave']);
        $this->assertSame('Jun/2026', $colunas[0]['label']);
        $this->assertFalse($colunas[0]['referencia']);
        $this->assertTrue($colunas[1]['referencia']);
        $this->assertSame('2026-07', $colunas[1]['chave']);
        $this->assertSame('2027-06', $colunas[12]['chave']);
    }

    public function test_build_colunas_virada_de_ano(): void
    {
        $colunas = $this->service->buildColunas(Carbon::create(2026, 1, 1));

        $this->assertSame('2025-12', $colunas[0]['chave']);
        $this->assertSame('2026-12', $colunas[12]['chave']);
    }

    public function test_fonte_celula_e_merge(): void
    {
        $fonte = new \ReflectionMethod(ProjecaoFaturasService::class, 'fonteCelula');
        $fonte->setAccessible(true);
        $merge = new \ReflectionMethod(ProjecaoFaturasService::class, 'mergeFonte');
        $merge->setAccessible(true);

        $this->assertSame('vazio', $fonte->invoke($this->service, 0.0, 0.0, false));
        $this->assertSame('projecao', $fonte->invoke($this->service, 0.0, 10.0, false));
        $this->assertSame('misto', $fonte->invoke($this->service, 5.0, 10.0, true));
        $this->assertSame('parcial', $fonte->invoke($this->service, 5.0, 0.0, true));

        $this->assertSame('misto', $merge->invoke($this->service, 'fatura', 'projecao'));
        $this->assertSame('fatura', $merge->invoke($this->service, 'vazio', 'fatura'));
    }

    public function test_enrich_celula_com_limite(): void
    {
        $method = new \ReflectionMethod(ProjecaoFaturasService::class, 'enrichCelulaComLimite');
        $method->setAccessible(true);

        $semLimite = $method->invoke($this->service, [
            'realizado' => 100.0,
            'projetado' => 50.0,
            'total' => 150.0,
            'fonte' => 'misto',
        ], null);

        $this->assertSame(150.0, $semLimite['em_uso']);
        $this->assertNull($semLimite['percentual_utilizado']);
        $this->assertNull($semLimite['percentual_livre']);
        $this->assertNull($semLimite['livre']);
        $this->assertNull($semLimite['disponivel']);

        $comLimite = $method->invoke($this->service, [
            'realizado' => 800.0,
            'projetado' => 200.0,
            'total' => 1000.0,
            'fonte' => 'misto',
        ], 5000.0);

        $this->assertSame(1000.0, $comLimite['em_uso']);
        $this->assertSame(20.0, $comLimite['percentual_utilizado']);
        $this->assertSame(80.0, $comLimite['percentual_livre']);
        $this->assertSame(4000.0, $comLimite['livre']);
        $this->assertSame(4000.0, $comLimite['disponivel']);
    }

    public function test_resumo_eu_outros_e_percentual_participacao(): void
    {
        $method = new \ReflectionMethod(ProjecaoFaturasService::class, 'buildResumoEuOutrosFromLinhas');
        $method->setAccessible(true);

        $linhas = [
            [
                'responsavel_id' => 1,
                'valores' => [
                    ['realizado' => 100.0, 'projetado' => 500.0, 'total' => 600.0],
                ],
            ],
            [
                'responsavel_id' => 2,
                'valores' => [
                    ['realizado' => 50.0, 'projetado' => 350.0, 'total' => 400.0],
                ],
            ],
        ];

        $resumo = $method->invoke($this->service, $linhas, 1, 1, 8000.0);

        $this->assertSame(600.0, $resumo[0]['meu']['total']);
        $this->assertSame(60.0, $resumo[0]['meu']['percentual']);
        $this->assertSame(7.5, $resumo[0]['meu']['percentual_do_limite']);
        $this->assertSame(400.0, $resumo[0]['outros']['total']);
        $this->assertSame(40.0, $resumo[0]['outros']['percentual']);
        $this->assertSame(5.0, $resumo[0]['outros']['percentual_do_limite']);
        $this->assertSame(1000.0, $resumo[0]['total']);
    }

    public function test_uso_limite_soma_fatura_atual_e_posteriores(): void
    {
        $method = new \ReflectionMethod(ProjecaoFaturasService::class, 'attachUsoLimite');
        $method->setAccessible(true);

        $linha = [
            'limite_credito' => 8000.0,
            'valores' => [
                [
                    'total' => 3000.0,
                    'meu' => ['realizado' => 3000.0, 'projetado' => 0.0, 'total' => 3000.0],
                    'outros' => ['realizado' => 0.0, 'projetado' => 0.0, 'total' => 0.0],
                ],
                [
                    'total' => 1000.0,
                    'meu' => ['realizado' => 600.0, 'projetado' => 0.0, 'total' => 600.0],
                    'outros' => ['realizado' => 400.0, 'projetado' => 0.0, 'total' => 400.0],
                ],
                [
                    'total' => 500.0,
                    'meu' => ['realizado' => 0.0, 'projetado' => 200.0, 'total' => 200.0],
                    'outros' => ['realizado' => 0.0, 'projetado' => 300.0, 'total' => 300.0],
                ],
            ],
        ];

        $colunas = [
            ['referencia' => false],
            ['referencia' => true],
            ['referencia' => false],
        ];

        $uso = $method->invoke($this->service, $linha, $colunas)['uso_limite'];

        $this->assertSame(1500.0, $uso['em_uso']);
        $this->assertSame(18.8, $uso['percentual_em_uso']);
        $this->assertSame(6500.0, $uso['livre']);
        $this->assertSame(81.3, $uso['percentual_livre']);
        $this->assertSame(800.0, $uso['meu']['total']);
        $this->assertSame(10.0, $uso['meu']['percentual_do_limite']);
        $this->assertSame(53.3, $uso['meu']['percentual']);
        $this->assertSame(700.0, $uso['outros']['total']);
        $this->assertSame(8.8, $uso['outros']['percentual_do_limite']);
        $this->assertSame(46.7, $uso['outros']['percentual']);
    }

    public function test_uso_limite_sem_limite_cadastrado_mantem_soma(): void
    {
        $method = new \ReflectionMethod(ProjecaoFaturasService::class, 'attachUsoLimite');
        $method->setAccessible(true);

        $linha = [
            'limite_credito' => null,
            'valores' => [
                ['total' => 200.0],
                ['total' => 50.0],
            ],
        ];

        $uso = $method->invoke($this->service, $linha, [
            ['referencia' => false],
            ['referencia' => true],
        ])['uso_limite'];

        $this->assertSame(50.0, $uso['em_uso']);
        $this->assertNull($uso['percentual_em_uso']);
        $this->assertNull($uso['livre']);
        $this->assertNull($uso['meu']);
        $this->assertNull($uso['outros']);
    }

    public function test_percentual_de(): void
    {
        $method = new \ReflectionMethod(ProjecaoFaturasService::class, 'percentualDe');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($this->service, 10.0, null));
        $this->assertNull($method->invoke($this->service, 10.0, 0.0));
        $this->assertSame(25.0, $method->invoke($this->service, 250.0, 1000.0));
    }
}
