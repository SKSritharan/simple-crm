<?php

namespace App\Mcp;

use App\Enums\DealStage;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

class CrmRecords
{
    public const MODELS = [
        'company' => Company::class,
        'contact' => Contact::class,
        'deal' => Deal::class,
        'task' => Task::class,
        'note' => Note::class,
    ];

    public const FIELDS = [
        'company' => ['name', 'website', 'email', 'phone', 'address', 'owner_id'],
        'contact' => ['first_name', 'last_name', 'email', 'phone', 'job_title', 'company_id', 'owner_id'],
        'deal' => ['title', 'company_id', 'contact_id', 'owner_id', 'stage', 'amount', 'currency', 'expected_close_date', 'closed_at', 'lost_reason'],
        'task' => ['title', 'description', 'assigned_to_id', 'due_at', 'completed_at', 'status', 'taskable_type', 'taskable_id'],
        'note' => ['body', 'noteable_type', 'noteable_id', 'author_id'],
    ];

    /** @return array<string, mixed> */
    public static function serialize(string $type, Model $record): array
    {
        return ['type' => $type, ...Arr::only($record->attributesToArray(), ['id', ...self::FIELDS[$type], 'created_at', 'updated_at'])];
    }

    public static function find(string $type, int $id): ?Model
    {
        return self::MODELS[$type]::query()->find($id);
    }

    /** @return array<string, mixed> */
    public static function schema(JsonSchema $schema, string $type, bool $updating): array
    {
        $fields = [];
        if ($updating) {
            $fields['id'] = $schema->integer()->min(1)->required();
        }

        foreach (self::FIELDS[$type] as $field) {
            $definition = str_ends_with($field, '_id')
                ? $schema->integer()->min(1)
                : $schema->string();
            if ($field === 'amount') {
                $definition = $schema->number()->min(0)->max(9999999999999.99);
            }
            if ($field === 'stage') {
                $definition = $schema->string()->enum(array_column(DealStage::cases(), 'value'));
            }
            if ($field === 'status') {
                $definition = $schema->string()->enum(array_column(TaskStatus::cases(), 'value'));
            }
            if (str_ends_with($field, '_type')) {
                $definition = $schema->string()->enum(['company', 'contact', 'deal']);
            }

            $required = in_array($field, ['name', 'first_name', 'title', 'body'], true)
                || ($type === 'note' && in_array($field, ['noteable_type', 'noteable_id'], true));
            if (! $updating && $required) {
                $definition->required();
            }
            if (! $required && ! in_array($field, ['stage', 'status', 'amount', 'currency'], true)) {
                $definition->nullable();
            }
            $fields[$field] = $definition->description('CRM '.$field.'.');
        }

        return $fields;
    }

    public static function save(Request $request, string $type, bool $updating): ?Model
    {
        $record = null;
        if ($updating) {
            $request->validate(['id' => ['required', 'integer', 'min:1']]);
            $record = self::find($type, (int) $request->get('id'));
            if ($record === null) {
                return null;
            }
        }

        $rules = [];
        foreach (self::FIELDS[$type] as $field) {
            $required = in_array($field, ['name', 'first_name', 'title', 'body', 'stage', 'status', 'amount', 'currency'], true);
            $rules[$field] = [$updating ? 'sometimes' : ($required && in_array($field, ['name', 'first_name', 'title', 'body'], true) ? 'required' : 'sometimes')];
            $rules[$field][] = $required ? 'required' : 'nullable';
            $rules[$field][] = str_ends_with($field, '_id') ? 'integer' : 'string';
            if (! str_ends_with($field, '_id') && ! in_array($field, ['body', 'description', 'address', 'lost_reason'], true)) {
                $rules[$field][] = 'max:255';
            }
        }

        foreach (['owner_id', 'author_id', 'assigned_to_id'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field][] = Rule::exists('users', 'id');
            }
        }
        foreach (['company_id' => 'companies', 'contact_id' => 'contacts'] as $field => $table) {
            if (isset($rules[$field])) {
                $rules[$field][] = Rule::exists($table, 'id')->whereNull('deleted_at');
            }
        }
        foreach (['email' => 'email', 'website' => 'url', 'due_at' => 'date', 'completed_at' => 'date', 'expected_close_date' => 'date', 'closed_at' => 'date'] as $field => $rule) {
            if (isset($rules[$field])) {
                $rules[$field][] = $rule;
            }
        }
        if ($type === 'deal') {
            $rules['stage'] = ['sometimes', 'required', Rule::enum(DealStage::class)];
            $rules['amount'] = ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];
            $rules['currency'] = ['sometimes', 'required', 'regex:/^[A-Za-z]{3}$/'];
        }
        if ($type === 'task') {
            $rules['status'] = ['sometimes', 'required', Rule::enum(TaskStatus::class)];
        }

        $parent = match ($type) {
            'note' => 'noteable',
            'task' => 'taskable',
            default => null,
        };
        if ($parent !== null) {
            $rules[$parent.'_type'] = ['sometimes', 'nullable', Rule::in(['company', 'contact', 'deal'])];
        }

        $data = $request->validate($rules);
        if ($updating && $data === []) {
            throw ValidationException::withMessages(['fields' => 'Supply at least one field to update.']);
        }
        if ($parent !== null) {
            $parentType = array_key_exists($parent.'_type', $data) ? $data[$parent.'_type'] : $record?->getAttribute($parent.'_type');
            $parentId = array_key_exists($parent.'_id', $data) ? $data[$parent.'_id'] : $record?->getAttribute($parent.'_id');
            if (($type === 'note' && ($parentType === null || $parentId === null))
                || (($parentType === null) !== ($parentId === null))
                || ($parentType !== null && self::find($parentType, (int) $parentId) === null)) {
                throw ValidationException::withMessages([$parent.'_id' => 'Supply a valid active company, contact, or deal with both parent type and ID.']);
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        if ($type === 'deal' && (! $updating || array_intersect(['stage', 'closed_at', 'lost_reason'], array_keys($data)) !== [])) {
            $stage = $data['stage'] ?? $record?->stage?->value ?? DealStage::New->value;
            if (! in_array($stage, ['won', 'lost'], true)) {
                $data['closed_at'] = null;
            } elseif ($record === null || $record->stage->value !== $stage) {
                $data['closed_at'] = $data['closed_at'] ?? now();
            }
            if ($stage !== 'lost') {
                $data['lost_reason'] = null;
            }
        }
        if ($type === 'task' && (! $updating || array_intersect(['status', 'completed_at'], array_keys($data)) !== [])) {
            $status = $data['status'] ?? $record?->status?->value ?? TaskStatus::Pending->value;
            if ($status !== 'completed') {
                $data['completed_at'] = null;
            } elseif ($record === null || $record->status !== TaskStatus::Completed) {
                $data['completed_at'] = $data['completed_at'] ?? now();
            }
        }

        $record ??= new (self::MODELS[$type]);
        $record->fill($data)->save();

        return $record->refresh();
    }
}
