<?php

namespace Flyo\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

class CspFrameAncestors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $allowed = 'frame-ancestors https://flyo.cloud';

        $existing = $response->headers->get('Content-Security-Policy');

        if ($existing) {
            $directives = array_filter(array_map('trim', explode(';', $existing)));

            $filtered = [];
            foreach ($directives as $dir) {
                if (stripos($dir, 'frame-ancestors') === 0) {
                    continue; // replace existing frame-ancestors
                }
                $filtered[] = $dir;
            }

            $filtered[] = $allowed;
            $csp = implode('; ', $filtered);
        } else {
            $csp = $allowed;
        }

        $response->headers->set('Content-Security-Policy', $csp);

        // Avoid conflicts: browsers prioritize CSP frame-ancestors over X-Frame-Options
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
