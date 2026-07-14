<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

trait Filter
{
    private function filterByRelationId(Request $request, $filterData, & $modelQuery) {
        $requestKey = $filterData['requestKey'];
        $relationName = (isset($filterData['relationName'])) ? $filterData['relationName'] : null;
        $relationNames = (isset($filterData['relationNames'])) ? $filterData['relationNames'] : null;
        $orWhereHas = isset($filterData['orWhereHas']) && $filterData['orWhereHas'];

        $relationIds = $request->get($requestKey);
        if (!isset($relationIds)) {
            return;
        }
        if (is_array($relationIds) && count($relationIds) === 0) {
            return;
        }
        if ($orWhereHas && is_array($relationNames) && count($relationNames) === 0) {
            return;
        }

        if (!is_array($relationNames)) {
            $relationNames = [$relationNames];
        }
        if (!is_array($relationIds)) {
            $relationIds = [$relationIds];
        }

        if ($orWhereHas) {
            foreach ($relationNames as $relationNameItem) {
                $modelQuery->orWhereHas($relationNameItem, function (Builder $query) use ($relationIds) {
                    $tableName = with($query)->getModel()->getTable();
                    $query->whereIn($tableName.'.id', $relationIds);
                });
            }
        } else {
            $modelQuery->whereHas($relationName, function (Builder $query) use ($relationIds) {
                $tableName = with($query)->getModel()->getTable();
                $query->whereIn($tableName.'.id', $relationIds);
            });
        }
    }

    private function filterByRelationKey(Request $request, $filterData, & $modelQuery) {

        $requestKey = $filterData['requestKey'];
        $exact = (isset($filterData['exact'])) ? $filterData['exact'] : false;
        $relationName = (isset($filterData['relationName'])) ? $filterData['relationName'] : null;
        $relationColumn = (isset($filterData['relationColumn'])) ? $filterData['relationColumn'] : null;

        $name = $request->get($requestKey);
        if (!isset($name)) {
            return;
        }
        $modelQuery->whereHas($relationName, function (Builder $query) use ($name, $relationColumn, $exact) {
            if ($exact) {
                $query->where($relationColumn, '=', $name);
            } else {
                $query->where($relationColumn, 'like', '%' . $name . '%');
            }

        });
    }

    private function filterByKey($request, $key, & $modelQuery) {
        $keyValue = trim($request->get($key));
        if (strlen($keyValue) > 0) {
            $modelQuery = $modelQuery->where($key, 'like', '%' . $keyValue . '%');
        }
    }

    private function filterByMultipleColumnKey(Request $request, $filterData, & $modelQuery) {
        $columns = $filterData['columns'];
        $requestKey = $filterData['requestKey'];
        $name = $request->get($requestKey);

        if (!isset($name) || strlen($name) == 0) {
            return;
        }

        $modelQuery->where(function ($query) use ($name, $columns) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%' . $name . '%');
            }
        });
    }

    private function filterOrByKey($request, $key, & $modelQuery) {
        $keyValue = trim($request->get($key));
        if (strlen($keyValue) > 0) {
            $modelQuery = $modelQuery->orWhere($key, 'like', '%' . $keyValue . '%');
        }
    }

    private function filterByKeyExact($request, $key, & $modelQuery) {
        $keyValue = trim($request->get($key));
        if (strlen($keyValue) > 0) {
            $modelQuery = $modelQuery->where($key, '=', $keyValue);
        }
    }

    private function filterOrByKeyExact($request, $key, & $modelQuery) {
        $keyValue = trim($request->get($key));
        if (strlen($keyValue) > 0) {
            $modelQuery = $modelQuery->orWhere($key, '=', $keyValue);
        }
    }

    private function filterByKeyIn($request, $key, & $modelQuery) {
        $keyValue = trim($request->get($key . '_in'));
        if (isset($keyValue) && is_array($keyValue)) {
            $modelQuery = $modelQuery->whereIn($key, $keyValue);
        }
    }

    private function filterByDate($request, & $modelQuery, &$filterDate) {

        $filterDate []= 'created_at';

        foreach ($filterDate as $ke=>$value) {

            if ($value === 'created_at') {
                $sinceDateKey = 'createdSinceDate';
                $tillDateKey = 'createdTillDate';
            } else {
                $sinceDateKey = $value.'_since_date';
                $tillDateKey = $value.'_till_date';
            }

            $sinceDate  = $request->get($sinceDateKey);
            $tillDate   = $request->get($tillDateKey);
            if (strlen($sinceDate) > 0 && strlen($tillDate) > 0) {
                $sinceDate  = Carbon::parse($sinceDate)->format('Y-m-d H:m:s');
                $tillDate   = Carbon::parse($tillDate)->format('Y-m-d H:m:s');
                $modelQuery = $modelQuery->whereBetween($value, [$sinceDate, $tillDate]);
            } else if (strlen($sinceDate) > 0) {
                $sinceDate  = Carbon::parse($sinceDate)->format('Y-m-d H:m:s');
                $modelQuery = $modelQuery->where($value, '>=', $sinceDate);
            } else if (strlen($tillDate) > 0) {
                $tillDate   = Carbon::parse($tillDate)->format('Y-m-d H:m:s');
                $modelQuery = $modelQuery->where($value, '<=', $tillDate);
            }
        }

//        $createdSinceDate  = $request->get('createdSinceDate');
//        $createdTillDate   = $request->get('createdTillDate');
//        if (strlen($createdSinceDate) > 0 && strlen($createdTillDate) > 0) {
//            $createdSinceDate = Carbon::parse($createdSinceDate)->format('Y-m-d H:m:s');
//            $createdTillDate  = Carbon::parse($createdTillDate)->format('Y-m-d H:m:s');
//            $modelQuery       = $modelQuery->whereBetween('created_at', [$createdSinceDate, $createdTillDate]);
//        } else if (strlen($createdSinceDate) > 0) {
//            $createdSinceDate = Carbon::parse($createdSinceDate)->format('Y-m-d H:m:s');
//            $modelQuery       = $modelQuery->where('created_at', '>=', $createdSinceDate);
//        } else if (strlen($createdTillDate) > 0) {
//            $createdTillDate  = Carbon::parse($createdTillDate)->format('Y-m-d H:m:s');
//            $modelQuery       = $modelQuery->where('created_at', '<=', $createdTillDate);
//        }
    }

    /**
     * Return a successful JSON response.
     *
     * @param mixed $data
     * @return JsonResponse
     */
    private function jsonResponseOk($data): JsonResponse {
        return response()->json($data, Response::HTTP_OK);
    }

    /**
     * Return a validation error JSON response.
     *
     * @param mixed $errors
     * @return JsonResponse
     */
    private function jsonResponseValidateError($errors): JsonResponse {
        return response()->json($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Return a server error JSON response.
     *
     * @param mixed $errors
     * @return JsonResponse
     */
    private function jsonResponseServerError($errors): JsonResponse {
        return response()->json($errors, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function checkOwner ($userOwnerId) {
        if (!Auth::user()->hasRole('Super Admin') && Auth::user()->id !== (int)$userOwnerId) {
            abort(403, 'Access denied');
        }
    }
}
