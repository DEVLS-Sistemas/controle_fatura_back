<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
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
     * @var RequestDataService $_requestService
     */
    protected $_requestService;

    public function __construct()
    {
        $this->_service = new DashboardService();
        $this->_requestService = new RequestDataService();
    }

    public function resumo(Request $request)
    {
        try {
            $objectAtributes = (object) $request->all();
            $result = $this->_service->handleResumo($objectAtributes);
            return response()->json($result, 200);
        } catch (Exception $ex) {
            $statusCode = is_numeric($ex->getCode()) ? (int) $ex->getCode() : 500;
            $statusCode = ($statusCode >= 100 && $statusCode <= 599) ? $statusCode : 500;
            return response()->json(['error' => true, 'message' => $ex->getMessage()], $statusCode);
        }
    }
}
