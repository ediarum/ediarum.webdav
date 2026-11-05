<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Small endpoints the SPA needs for itself rather than for any one page.
 */
class AppController extends Controller
{
    /**
     * Authenticated no-op. The SPA calls it after a 419 (the response carries a fresh XSRF-TOKEN
     * cookie) and after an EventSource error (a 401 here means the session is gone), since neither
     * case tells the frontend by itself whether the user is still logged in.
     */
    public function ping(): Response
    {
        return response()->noContent();
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'adminPanelEnabled' => (bool) config('app.admin_panel_enabled'),
        ]);
    }
}
