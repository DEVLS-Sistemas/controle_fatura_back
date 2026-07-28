<?php

namespace App\Services;


use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaginateService
{
    public function __construct()
    {
    }

    /**
     * @param query $query
     * @param int $page
     * @param int $perPage
     * @param array $options
     * 
     * @return LengthAwarePaginator
     */
    public function _paginate($query, $page = null, $perPage = null, $options = [], $totalRegistros = 0)
    {
        $page = $page ?: 1;
        $perPage = $perPage ?: 5;
        $totalRegisters = $totalRegistros > 0 ? $totalRegistros : $query->count();
        $query->offset(($page - 1) * $perPage)->limit($perPage);

        return new LengthAwarePaginator($query->get(), $totalRegisters, $perPage, $page, $options);
    }

    /**
     * Paginacao para rowQuery
     *
     * @param query $query_items
     * @param integer $page
     * @param integer $perPage
     * @param array $options
     * @param integer $totalRegistros
     * 
     * @return LengthAwarePaginator
     */
    public function _paginateRowQuery($query_items, $page = null, $perPage = null, $options = [],$totalRegistros =0)
    {
        $page = $page?:1;
        $perPage = $perPage?:5;
       
        if($totalRegistros == 0 ){                    
            $queryCount = DB::select($query_items);
            $totalRegistros =count($queryCount);          
        }
       
        $offset = ($page - 1) * $perPage;

        $query_items .= " LIMIT {$perPage} ";
        $query_items .= " OFFSET {$offset} ";

        
        $data = DB::select($query_items);        
        return new LengthAwarePaginator($data,$totalRegistros,$perPage,$page,$options);
    }
}
