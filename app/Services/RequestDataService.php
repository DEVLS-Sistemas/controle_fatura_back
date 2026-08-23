<?php

namespace App\Services;

class RequestDataService
{
    /**
     * Payload do request sem `user_id` do client (dono vem só do token).
     */
    public function fromRequest($request): object
    {
        $attrs = (object) $request->all();
        unset($attrs->user_id);

        return $attrs;
    }

    public function getAllParametersForQuery($request)
    {
        $parametros = $this->fromRequest($request);
        $parametros->page = $request->page;
        $parametros->perPage = $request->perPage;
        $parametros->url = $request->url();
        $parametros->query = $request->query();

        if (is_array($parametros->query)) {
            unset($parametros->query['user_id']);
        }

        return $parametros;
    }
}
