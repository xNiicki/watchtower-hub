<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * GET /api/v1/logs
     *
     * Stub — returns an empty list always.
     * Query params (host, severity, search, limit) are accepted but unused.
     * Plan C (syslog ingestion) will fill this endpoint with real data.
     *
     * @todo Plan C: implement syslog ingestion and populate log entries here.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Params accepted for forward-compatibility; unused until Plan C.
        $request->query('host');
        $request->query('severity');
        $request->query('search');
        $request->query('limit');

        return response()->json([]);
    }
}
