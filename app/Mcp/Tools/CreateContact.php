<?php

namespace App\Mcp\Tools;

use App\Mcp\CrmRecords;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-contact')]
#[Description('Create a contact. Optional ownership, assignment and authorship remain unset unless supplied.')]
class CreateContact extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $record = CrmRecords::save($request, 'contact', false);

        return $record === null
            ? Response::error('Active contact not found. Use search-crm to find a valid ID.')
            : Response::structured(['record' => CrmRecords::serialize('contact', $record)]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return CrmRecords::schema($schema, 'contact', false);
    }
}
