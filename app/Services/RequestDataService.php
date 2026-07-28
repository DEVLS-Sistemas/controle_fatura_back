<?php

namespace App\Services;

class RequestDataService
{

    //Método responsável pela extração de parâmetros para uso de queries
    public function getAllParametersForQuery($request)
    {
        $parametros = (object)$request->all();
        $parametros->page = $request->page;
        $parametros->perPage = $request->perPage;
        $parametros->url = $request->url();
        $parametros->query = $request->query();
        return  $parametros;
    }
}
