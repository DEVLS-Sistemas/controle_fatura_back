<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\ProjecaoFaturasService;
use App\Services\Dashboard\RankingParceladasService;
use App\Services\RequestDataService;
use Exception;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * @var DashboardService $_service
     */
    private DashboardService $_service;

    /**
     * @var ProjecaoFaturasService $_projecaoService
     */
    private ProjecaoFaturasService $_projecaoService;

    /**
     * @var RankingParceladasService $_rankingParceladasService
     */
    private RankingParceladasService $_rankingParceladasService;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new DashboardService();
        $this->_projecaoService = new ProjecaoFaturasService();
        $this->_rankingParceladasService = new RankingParceladasService();
        $this->_requestService = new RequestDataService();
    }

    public function resumo(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleResumo($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function projecaoFaturas(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_projecaoService->handleProjecao($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function rankingParceladas(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_rankingParceladasService->handleRanking($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
