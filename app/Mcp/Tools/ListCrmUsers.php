<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-crm-users')]
#[Description('List user IDs and names for owner_id, assigned_to_id and author_id. No credentials are exposed.')]
#[IsReadOnly]
class ListCrmUsers extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = User::query()->orderBy('id')->paginate($data['per_page'] ?? 25, ['id', 'name'], 'page', $data['page'] ?? 1);

        return Response::structured([
            'users' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return ['page' => $schema->integer()->min(1)->default(1), 'per_page' => $schema->integer()->min(1)->max(100)->default(25)];
    }
}
