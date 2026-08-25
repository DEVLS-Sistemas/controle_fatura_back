<?php

namespace App\Http\Controllers;

use App\Services\Assinatura\AssinaturaService;
use App\Services\RequestDataService;
use Exception;
use Illuminate\Http\Request;

class AssinaturaController extends Controller
{
    /**
     * @var AssinaturaService $_service
     */
    private AssinaturaService $_service;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new AssinaturaService();
        $this->_requestService = new RequestDataService();
    }

    public function listarLookupsAssinatura()
    {
        try {
            $result = $this->_service->handleLookupsAssinatura();
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarAssinatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result = $this->_service->handleListarAssinatura($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarAssinaturaId(Request $request, string $id)
    {
        try {
            $result = $this->_service->getAssinaturaId($id, $this->_requestService->fromRequest($request));
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function createAssinatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleAddAssinatura($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function editAssinatura(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->fromRequest($request);
            $result = $this->_service->handleEditAssinatura($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function deleteAssinatura(string $id)
    {
        try {
            $result = $this->_service->handleDeleteAssinatura($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarAssinaturaAsync(Request $request)
    {
        try {
            $params = $this->_requestService->fromRequest($request);
            $result = $this->_service->getAssinaturaAsync($params);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
