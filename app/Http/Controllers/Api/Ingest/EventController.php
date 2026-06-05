<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Alerting\AppEventRecorder;
use App\Http\Controllers\Controller;
use App\Models\MonitoredApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function __construct(private readonly AppEventRecorder $recorder) {}

    /**
     * POST /api/ingest/event — record one (throttled, possibly aggregated) app event.
     */
    public function __invoke(Request $request): Response
    {
        $app = $request->user();

        if (! $app instanceof MonitoredApp) {
            abort(403, 'This token is not associated with a monitored app.');
        }

        $data = $request->validate([
            'slug' => ['required', 'string'],
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'fingerprint' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:exception,failed_job,failed_scheduled_task'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
            'exceptionClass' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'string', 'max:1024'],
            'line' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'trace' => ['nullable', 'string', 'max:65000'],
            'context' => ['nullable', 'array'],
            'occurrences' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'occurredAt' => ['required', 'date'],
        ]);

        if ($data['slug'] !== $app->slug) {
            abort(403, 'Payload slug does not match the authenticated app.');
        }

        $this->recorder->record($app, $data);

        return response()->noContent();
    }
}
