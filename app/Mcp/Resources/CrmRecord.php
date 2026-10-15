<?php

namespace App\Mcp\Resources;

use App\Mcp\CrmRecords;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[MimeType('application/json')]
#[Description('Read an active CRM record using its singular type and ID.')]
class CrmRecord extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('crm://records/{type}/{id}');
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', 'in:company,contact,deal,task,note'], 'id' => ['required', 'integer', 'min:1']]);
        $record = CrmRecords::find($data['type'], (int) $data['id']);

        return $record === null
            ? Response::error('Active CRM record not found. Use search-crm to find a valid type and ID.')
            : Response::json(['record' => CrmRecords::serialize($data['type'], $record)]);
    }
}
