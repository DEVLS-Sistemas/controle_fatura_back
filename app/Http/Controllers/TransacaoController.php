<?php

namespace App\Http\Controllers;

use App\Services\RequestDataService;
use App\Services\Transacao\CompraAnexoService;
use App\Services\Transacao\CompraHistoricoService;
use App\Services\Transacao\CompraVisualizacaoService;
use App\Services\Transacao\ConciliacaoService;
use App\Services\Transacao\TransacaoService;
use Exception;
use Illuminate\Http\Request;

class TransacaoController extends Controller
{
    /**
     * @var TransacaoService $_service
     */
    private TransacaoService $_service;

    /**
     * @var CompraVisualizacaoService $_visualizacaoService
     */
    private CompraVisualizacaoService $_visualizacaoService;

    /**
     * @var ConciliacaoService $_conciliacaoService
     */
    private ConciliacaoService $_conciliacaoService;

    /**
     * @var CompraAnexoService $_anexoService
     */
    private CompraAnexoService $_anexoService;

    /**
     * @var CompraHistoricoService $_historicoService
     */
    private CompraHistoricoService $_historicoService;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new TransacaoService();
        $this->_visualizacaoService = new CompraVisualizacaoService();
        $this->_conciliacaoService = new ConciliacaoService();
        $this->_anexoService = new CompraAnexoService();
        $this->_historicoService = new CompraHistoricoService();
        $this->_requestService = new RequestDataService();
    }

    public function listarLookupsTransacao()
    {
        try {
            $result = $this->_service->handleLookupsTransacao();
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarTransacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result = $this->_service->getTransacaoPaginate($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarTransacaoId(string $id)
    {
        try {
            $result = $this->_service->getTransacaoId($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function visualizarCompra(Request $request, string $identificador)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_visualizacaoService->handleVisualizar($identificador, $objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function createTransacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleAddTransacao($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function editTransacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleEditTransacao($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function deleteTransacao(Request $request, string $id)
    {
        try {
            $excluirGrupo = filter_var(
                $request->query('excluir_grupo', $request->input('excluir_grupo', false)),
                FILTER_VALIDATE_BOOLEAN
            );
            $result = $this->_service->handleDeleteTransacao($id, $excluirGrupo);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarTransacaoAsync(Request $request)
    {
        try {
            $params = $this->_requestService->fromRequest($request);
            $result = $this->_service->getTransacaoAsync($params);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function exportarTransacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $csv = $this->_service->exportTransacoesCsv($objectAtributes);
            $filename = 'transacoes_' . date('Ymd_His') . '.csv';

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarEstabelecimentosDoFiltro(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result = $this->_service->getEstabelecimentosDoFiltro($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarCandidatosConciliacao(string $identificador)
    {
        try {
            $result = $this->_conciliacaoService->handleListarCandidatos($identificador);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function conciliarTransacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_conciliacaoService->handleConciliar($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function desvincularConciliacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_conciliacaoService->handleDesvincular($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function rejeitarConciliacao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_conciliacaoService->handleRejeitar($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function listarAnexosCompra(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_anexoService->handleListar($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function cadastrarAnexoCompra(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_anexoService->handleCadastrar($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function excluirAnexoCompra(string $id)
    {
        try {
            $result = $this->_anexoService->handleExcluir($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function downloadAnexoCompra(string $id)
    {
        try {
            $file = $this->_anexoService->resolveDownload($id);

            return response()->file($file['path'], [
                'Content-Type' => $file['mime'],
                'Content-Disposition' => 'inline; filename="' . $file['nome'] . '"',
            ]);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    public function listarHistoricoCompra(string $identificador)
    {
        try {
            $result = $this->_historicoService->handleListar($identificador);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            return $this->jsonError($ex);
        }
    }

    private function jsonError(Exception $ex)
    {
        $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
        $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;

        return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
    }
}
