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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-crm-record')]
#[Description('Read an active CRM record including its relationship IDs. Use search-crm for related records.')]
#[IsReadOnly]
class GetCrmRecord extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['type' => ['required', 'in:company,contact,deal,task,note'], 'id' => ['required', 'integer', 'min:1']]);
        $record = CrmRecords::find($data['type'], (int) $data['id']);

        return $record === null
            ? Response::error('Active CRM record not found. Use search-crm to find a valid type and ID.')
            : Response::structured(['record' => CrmRecords::serialize($data['type'], $record)]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['type' => $schema->string()->enum(array_keys(CrmRecords::MODELS))->required(), 'id' => $schema->integer()->min(1)->required()];
    }
}
