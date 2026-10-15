<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CrmAppController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        Gate::authorize('use-ai-assistant');

        return response()->view('mcp.crm-explorer-app')->header('Content-Security-Policy',
            "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'"
        )->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
