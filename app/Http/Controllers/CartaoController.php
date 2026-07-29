<?php

namespace App\Http\Controllers;

use App\Services\Cartao\CartaoService;
use App\Services\RequestDataService;
use Exception;
use Illuminate\Http\Request;

class CartaoController extends Controller
{
    /**
     * @var CartaoService $_service
     */
    private CartaoService $_service;

    /**
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new CartaoService();
        $this->_requestService = new RequestDataService();
    }

    public function listarLookupsCartao()
    {
        try {
            $result = $this->_service->handleLookupsCartao();
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarCartao(Request $request)
    {
        try {
            $objectAtributes = $this->_requestService->getAllParametersForQuery($request);
            $result = $this->_service->getCartaoPaginate($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarCartaoId(string $id)
    {
        try {
            $result = $this->_service->getCartaoId($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function createCartao(Request $request)
    {
        try {
            $objectAtributes = (object) $request->all();
            $result = $this->_service->handleAddCartao($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function editCartao(Request $request)
    {
        try {
            $objectAtributes = (object) $request->all();
            $result = $this->_service->handleEditCartao($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function deleteCartao(string $id)
    {
        try {
            $result = $this->_service->handleDeleteCartao($id);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }

    public function listarCartaoAsync(Request $request)
    {
        try {
            $params = (object) $request->all();
            $result = $this->_service->getCartaoAsync($params);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
