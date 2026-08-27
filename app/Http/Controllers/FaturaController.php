<?php

namespace App\Http\Controllers;

use App\Exceptions\FaturaSelecaoException;
use App\Exceptions\PdfPasswordException;
use App\Services\Fatura\FaturaService;
use App\Services\RequestDataService;
use Exception;
use Illuminate\Http\Request;

class FaturaController extends Controller
{
    /**
     * @var FaturaService $_service
     */
    private FaturaService $_service;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new FaturaService();
        $this->_requestService = new RequestDataService();
    }

    public function listarLookupsFatura()
    {
        try {
            $result = $this->_service->handleLookupsFatura();
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarFatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result = $this->_service->getFaturaPaginate($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarFaturaId(string $id)
    {
        try {
            $result = $this->_service->getFaturaId($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function createFatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            if ($request->hasFile('arquivo_pdf')) {
                $objectAtributes->arquivo_pdf = $request->file('arquivo_pdf');
            }
            $result = $this->_service->handleAddFatura($objectAtributes);
            return response()->json($result, 200);
        } catch (FaturaSelecaoException $ex) {
            return response()->json($ex->toResponseArray(), 422);
        } catch (PdfPasswordException $ex) {
            return response()->json([
                'error' => true,
                'message' => $ex->getMessage(),
                'codigo' => $ex->codigo(),
                'precisa_senha_pdf' => true,
                'senha_pdf' => $ex->payload(),
            ], 422);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function editFatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleEditFatura($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function deleteFatura(string $id)
    {
        try {
            $result = $this->_service->handleDeleteFatura($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function deleteTodasFaturas(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleDeleteTodasFaturas($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarFaturaAsync(Request $request)
    {
        try {
            $params = $this->_requestService->fromRequest($request);
            $result = $this->_service->getFaturaAsync($params);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function uploadPdf(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            if ($request->hasFile('arquivo_pdf')) {
                $objectAtributes->arquivo_pdf = $request->file('arquivo_pdf');
            }
            $result = $this->_service->handleUploadPdf($objectAtributes);
            return response()->json($result, 200);
        } catch (FaturaSelecaoException $ex) {
            return response()->json($ex->toResponseArray(), 422);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function processarPdf(Request $request, string $id)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleProcessarPdf($id, $objectAtributes);
            return response()->json($result, 200);
        } catch (PdfPasswordException $ex) {
            return response()->json([
                'error' => true,
                'message' => $ex->getMessage(),
                'codigo' => $ex->codigo(),
                'precisa_senha_pdf' => true,
                'senha_pdf' => $ex->payload(),
            ], 422);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function downloadPdf(string $id)
    {
        return $this->downloadAnexo($id, 'pdf');
    }

    public function downloadCsv(string $id)
    {
        return $this->downloadAnexo($id, 'csv');
    }

    public function impactoRemoverAnexo(string $id)
    {
        try {
            $result = $this->_service->handleImpactoRemoverAnexo($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function comprasParaReconcilia(string $id)
    {
        try {
            $result = $this->_service->handleComprasParaReconcilia($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function removerAnexo(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            if ($request->hasFile('arquivo_pdf')) {
                $objectAtributes->arquivo_pdf = $request->file('arquivo_pdf');
            }
            $result = $this->_service->handleRemoverAnexo($objectAtributes);
            return response()->json($result, 200);
        } catch (PdfPasswordException $ex) {
            return response()->json([
                'error' => true,
                'message' => $ex->getMessage(),
                'codigo' => $ex->codigo(),
                'precisa_senha_pdf' => true,
                'senha_pdf' => $ex->payload(),
            ], 422);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    private function downloadAnexo(string $id, string $tipo)
    {
        try {
            $path = $tipo === 'pdf'
                ? $this->_service->downloadPdf($id)
                : $this->_service->downloadCsv($id);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: ($tipo === 'pdf' ? 'pdf' : 'csv'));
            $mime = match ($extension) {
                'pdf' => 'application/pdf',
                'csv' => 'text/csv',
                'xml' => 'application/xml',
                'txt' => 'text/plain',
                default => 'application/octet-stream',
            };

            return response()->file($path, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="fatura-' . $id . '.' . $extension . '"',
            ]);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
