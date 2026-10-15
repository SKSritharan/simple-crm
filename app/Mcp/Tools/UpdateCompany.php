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

#[Name('update-company')]
#[Description('Update supplied fields on an active company. Omitted fields remain unchanged; null clears nullable fields.')]
class UpdateCompany extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $record = CrmRecords::save($request, 'company', true);

        return $record === null
            ? Response::error('Active company not found. Use search-crm to find a valid ID.')
            : Response::structured(['record' => CrmRecords::serialize('company', $record)]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return CrmRecords::schema($schema, 'company', true);
    }
}
