<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\AppMeta;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Read-only CRM explorer with search, pagination, sorting, status filters, and record details.')]
#[AppMeta]
class CrmExplorerApp extends AppResource
{
    /**
     * Handle the app resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::view('mcp.crm-explorer-app', [
            'title' => $this->title(),
        ]);
    }
}
