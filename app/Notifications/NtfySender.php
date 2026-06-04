<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;

class NtfySender
{
    /**
     * Whether ntfy is configured (base_url is set).
     * A listener with disabled() → true skips sending and logs an informational message.
     */
    public function enabled(): bool
    {
        return ! empty(config('watchtower.ntfy.base_url'));
    }

    /**
     * POST a notification to the configured ntfy topic.
     *
     * ntfy headers used:
     *   Title    — notification title shown in the app
     *   Priority — 'urgent' for fired-critical, 'default' for recovery
     *   Tags     — comma-joined emoji shortcode list (e.g. 'rotating_light')
     *
     * Bearer Authorization is included when NTFY_TOKEN is set.
     * Throws on non-2xx via ->throw() — callers must catch Throwable.
     *
     * @param  array<string>  $tags
     */
    public function send(string $title, string $message, string $priority, array $tags = []): void
    {
        $baseUrl = rtrim((string) config('watchtower.ntfy.base_url'), '/');
        $topic = config('watchtower.ntfy.topic', 'watchtower');
        $token = config('watchtower.ntfy.token');

        $headers = [
            'Title' => $title,
            'Priority' => $priority,
        ];

        if (! empty($tags)) {
            $headers['Tags'] = implode(',', $tags);
        }

        $request = Http::timeout(5)->withHeaders($headers);

        if (! empty($token)) {
            $request = $request->withToken($token);
        }

        $request->withBody($message, 'text/plain')->post("{$baseUrl}/{$topic}")->throw();
    }
}
