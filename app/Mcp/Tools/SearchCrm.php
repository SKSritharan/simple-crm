<?php

namespace App\Mcp\Tools;

use App\Mcp\CrmRecords;
use App\Mcp\Resources\CrmExplorerApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-crm')]
#[Description('Search active CRM records by type and optional text. Browse all pages for complete results.')]
#[IsReadOnly]
#[RendersApp(resource: CrmExplorerApp::class)]
class SearchCrm extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'type' => ['required', 'in:company,contact,deal,task,note'],
            'text' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:pending,completed,cancelled'],
            'stage' => ['sometimes', 'string', 'in:new,qualified,proposal,negotiation,won,lost'],
            'sort' => ['sometimes', 'in:id,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        $type = $data['type'];
        $columns = match ($type) {
            'company' => ['name', 'email'],
            'contact' => ['first_name', 'last_name', 'email'],
            'deal', 'task' => ['title'],
            'note' => ['body'],
        };
        $query = CrmRecords::MODELS[$type]::query();
        if ($type === 'task' && isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($type === 'deal' && isset($data['stage'])) {
            $query->where('stage', $data['stage']);
        }
        if (isset($data['text']) && $data['text'] !== '') {
            $query->where(function (Builder $query) use ($columns, $data): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$data['text'].'%');
                }
            });
        }
        $sort = $data['sort'] ?? 'id';
        $query->orderBy($sort, $data['direction'] ?? 'asc');
        if ($sort !== 'id') {
            $query->orderBy('id');
        }
        $page = $query->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return Response::structured([
            'records' => $page->getCollection()->map(fn (Model $record): array => CrmRecords::serialize($type, $record))->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'query' => $data,
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(CrmRecords::MODELS))->required(),
            'text' => $schema->string()->description('Search names, titles, email addresses, or note bodies; SQL LIKE wildcards are supported.'),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25),
            'status' => $schema->string()->enum(['pending', 'completed', 'cancelled'])->description('Task status filter.'),
            'stage' => $schema->string()->enum(['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])->description('Deal stage filter.'),
            'sort' => $schema->string()->enum(['id', 'created_at']),
            'direction' => $schema->string()->enum(['asc', 'desc']),
        ];
    }
}
