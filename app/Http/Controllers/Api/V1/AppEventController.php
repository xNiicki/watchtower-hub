<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppEventController extends Controller
{
    /**
     * GET /api/v1/apps/{slug}/events
     *
     * Flat array of an app's grouped events, newest last_seen first. Returns
     * title/message/counts only — trace/context are deliberately not exposed to
     * the mobile list (that's the D4 detail view / hub admin).
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $app = MonitoredApp::where('slug', $slug)->firstOrFail();

        $search = $request->query('search');
        $limit = min(max((int) $request->query('limit', 100), 1), 1000);

        $events = AppEvent::query()
            ->select(['id', 'type', 'severity', 'title', 'message', 'occurrences', 'first_seen_at', 'last_seen_at'])
            ->where('app_id', $app->id)
            ->when(is_string($search) && $search !== '', fn ($q) => $q->where('message', 'ilike', '%'.$search.'%'))
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();

        return response()->json(
            $events->map(fn (AppEvent $e) => [
                'id' => (string) $e->id,
                'type' => $e->type,
                'severity' => $e->severity,
                'title' => $e->title,
                'message' => $e->message,
                'occurrences' => $e->occurrences,
                'firstSeenAt' => $e->first_seen_at->toIso8601String(),
                'lastSeenAt' => $e->last_seen_at->toIso8601String(),
            ])->all()
        );
    }
}
