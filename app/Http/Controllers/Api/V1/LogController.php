<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyslogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * GET /api/v1/logs
     *
     * Real syslog query. Returns a FLAT array (no {data} wrapper) of entries
     * matching the app's App\Data\LogEntry DTO shape:
     * { id (string), host, severity, message, loggedAt (ISO8601 string) }.
     *
     * Query params (all optional, combinable):
     * - host:     exact match on host
     * - severity: exact match on severity
     * - search:   case-insensitive substring on message (ILIKE, trigram index)
     * - limit:    default 200, clamped to max 1000
     *
     * Ordered by logged_at DESC (newest first).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $host = $request->query('host');
        $severity = $request->query('severity');
        $search = $request->query('search');
        $limit = min(max((int) $request->query('limit', 200), 1), 1000);

        $entries = SyslogEntry::query()
            ->when(is_string($host) && $host !== '', fn ($query) => $query->where('host', $host))
            ->when(is_string($severity) && $severity !== '', fn ($query) => $query->where('severity', $severity))
            ->when(is_string($search) && $search !== '', fn ($query) => $query->where('message', 'ilike', '%'.$search.'%'))
            ->orderByDesc('logged_at')
            ->limit($limit)
            ->get();

        return response()->json(
            $entries->map(fn (SyslogEntry $entry) => [
                'id' => (string) $entry->id,
                'host' => $entry->host,
                'severity' => $entry->severity,
                'message' => $entry->message,
                'loggedAt' => $entry->logged_at->toIso8601String(),
            ])->all()
        );
    }
}
