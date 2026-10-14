<?php

use App\Enums\DealStage;
use App\Enums\TaskStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Notes\NoteResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

dataset('crm resources', [
    'companies' => [Company::class, CompanyResource::class, 'name'],
    'contacts' => [Contact::class, ContactResource::class, 'first_name'],
    'deals' => [Deal::class, DealResource::class, 'title'],
    'tasks' => [Task::class, TaskResource::class, 'title'],
    'notes' => [Note::class, NoteResource::class, 'body'],
]);

dataset('crm parents', [
    'company' => [Company::class, 'company'],
    'contact' => [Contact::class, 'contact'],
    'deal' => [Deal::class, 'deal'],
]);

test('guests are redirected to login from CRM pages', function (string $model, string $resource) {
    $record = $model::factory()->create();

    foreach (['index', 'create', 'edit'] as $page) {
        $this->get($resource::getUrl($page, ['record' => $record]))
            ->assertRedirect(Filament::getLoginUrl());
    }
})->with('crm resources');

test('panel users can open CRM pages and see records owned by other users', function (string $model, string $resource) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $otherUser = User::factory()->create();
    $record = $model::factory()->create([
        match ($model) {
            Task::class => 'assigned_to_id',
            Note::class => 'author_id',
            default => 'owner_id',
        } => $otherUser->id,
    ]);

    foreach (['index', 'create', 'edit'] as $page) {
        $this->get($resource::getUrl($page, ['record' => $record]))->assertOk();
    }

    Livewire::test($resource::getPages()['index']->getPage())
        ->assertCanSeeTableRecords([$record]);
})->with('crm resources');

test('CRM forms persist new records with current user defaults', function (string $model, string $resource, string $field) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $input = [$field => 'Created CRM record'];

    if ($model === Note::class) {
        $company = Company::factory()->create();
        $input += ['noteable_type' => 'company', 'noteable_id' => $company->id];
    }

    Livewire::test($resource::getPages()['create']->getPage())
        ->fillForm($input)
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas((new $model)->getTable(), $input + [
        match ($model) {
            Task::class => 'assigned_to_id',
            Note::class => 'author_id',
            default => 'owner_id',
        } => $user->id,
    ]);
})->with('crm resources');

test('CRM forms update existing records', function (string $model, string $resource, string $field) {
    $this->actingAs(User::factory()->create());
    $record = $model::factory()->create();

    Livewire::test($resource::getPages()['edit']->getPage(), ['record' => $record->id])
        ->fillForm([$field => 'Updated CRM record'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->getAttribute($field))->toBe('Updated CRM record');
})->with('crm resources');

test('CRM forms reject missing identifying fields', function (string $model, string $resource, string $field) {
    $this->actingAs(User::factory()->create());

    Livewire::test($resource::getPages()['create']->getPage())
        ->fillForm([$field => null])
        ->call('create')
        ->assertHasFormErrors([$field => 'required']);

    $this->assertDatabaseCount((new $model)->getTable(), 0);
})->with('crm resources');

test('CRM records can be individually soft deleted and restored without permanent deletion actions', function (string $model, string $resource) {
    $this->actingAs(User::factory()->create());
    $record = $model::factory()->create();

    Livewire::test($resource::getPages()['edit']->getPage(), ['record' => $record->id])
        ->assertActionDoesNotExist('forceDelete')
        ->callAction('delete');

    $this->assertSoftDeleted($record);

    Livewire::test($resource::getPages()['edit']->getPage(), ['record' => $record->id])
        ->callAction('restore');

    $this->assertNotSoftDeleted($record);
})->with('crm resources');

test('CRM list actions soft delete and restore selected records', function (string $model, string $resource) {
    $this->actingAs(User::factory()->create());
    $records = $model::factory()->count(2)->create();
    $page = $resource::getPages()['index']->getPage();

    Livewire::test($page)
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table()->bulk())
        ->selectTableRecords($records->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk());

    foreach ($records as $record) {
        $this->assertSoftDeleted($record);
    }

    Livewire::test($page)
        ->assertCanNotSeeTableRecords($records)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records->modelKeys())
        ->callAction(TestAction::make('restore')->table()->bulk());

    foreach ($records as $record) {
        $this->assertNotSoftDeleted($record);
    }
})->with('crm resources');

test('company contact and deal relationship selectors save readable associations', function () {
    $this->actingAs(User::factory()->create());
    $owner = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Acme']);
    $contact = Contact::factory()->for($company)->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    Livewire::test(ContactResource::getPages()['create']->getPage())
        ->fillForm(['first_name' => 'Alex', 'company_id' => $company->id, 'owner_id' => $owner->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('contacts', ['first_name' => 'Alex', 'company_id' => $company->id, 'owner_id' => $owner->id]);

    Livewire::test(DealResource::getPages()['create']->getPage())
        ->assertFormFieldExists('contact_id', fn (Select $field): bool => $field->getSearchResults('Doe') === [$contact->id => 'Jane Doe'])
        ->fillForm(['title' => 'New sale', 'company_id' => $company->id, 'contact_id' => $contact->id, 'owner_id' => $owner->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('deals', ['title' => 'New sale', 'company_id' => $company->id, 'contact_id' => $contact->id, 'owner_id' => $owner->id, 'stage' => 'new', 'amount' => 0, 'currency' => 'USD']);
});

test('task and note forms persist each supported parent alias', function (string $parentClass, string $alias) {
    $this->actingAs(User::factory()->create());
    $parent = $parentClass::factory()->create();

    Livewire::test(TaskResource::getPages()['create']->getPage())
        ->fillForm(['title' => 'Follow up', 'taskable_type' => $alias, 'taskable_id' => $parent->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tasks', ['title' => 'Follow up', 'taskable_type' => $alias, 'taskable_id' => $parent->id, 'status' => 'pending', 'completed_at' => null]);

    Livewire::test(NoteResource::getPages()['create']->getPage())
        ->fillForm(['body' => 'Meeting notes', 'noteable_type' => $alias, 'noteable_id' => $parent->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('notes', ['body' => 'Meeting notes', 'noteable_type' => $alias, 'noteable_id' => $parent->id]);
})->with('crm parents');

test('tasks can be unlinked and assignments can be cleared', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->for(Company::factory(), 'taskable')->for(User::factory(), 'assignedTo')->create();

    Livewire::test(TaskResource::getPages()['edit']->getPage(), ['record' => $task->id])
        ->fillForm(['taskable_type' => null, 'taskable_id' => null, 'assigned_to_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'taskable_type' => null, 'taskable_id' => null, 'assigned_to_id' => null]);
});

test('notes require a parent and reject unsupported or nonexistent parents', function (array $parentInput, array $errors) {
    $this->actingAs(User::factory()->create());

    Livewire::test(NoteResource::getPages()['create']->getPage())
        ->fillForm(['body' => 'A note'] + $parentInput)
        ->call('create')
        ->assertHasFormErrors($errors);

    $this->assertDatabaseCount('notes', 0);
})->with([
    'missing parent' => [[], ['noteable_type' => 'required']],
    'missing record' => [['noteable_type' => 'company'], ['noteable_id' => 'required']],
    'unsupported type' => [['noteable_type' => 'user', 'noteable_id' => 1], ['noteable_type' => 'in']],
    'nonexistent record' => [['noteable_type' => 'company', 'noteable_id' => 999], ['noteable_id' => 'in']],
]);

test('deal forms reject invalid enum amount currency and email values', function (string $resource, array $input, array $errors) {
    $this->actingAs(User::factory()->create());

    Livewire::test($resource::getPages()['create']->getPage())
        ->fillForm($input)
        ->call('create')
        ->assertHasFormErrors($errors);
})->with([
    'invalid deal stage' => [DealResource::class, ['title' => 'Sale', 'stage' => 'invalid'], ['stage' => 'in']],
    'invalid task status' => [TaskResource::class, ['title' => 'Task', 'status' => 'invalid'], ['status' => 'in']],
    'negative amount' => [DealResource::class, ['title' => 'Sale', 'amount' => -1], ['amount' => 'min']],
    'too much precision' => [DealResource::class, ['title' => 'Sale', 'amount' => '1.234'], ['amount' => 'decimal']],
    'invalid currency' => [DealResource::class, ['title' => 'Sale', 'currency' => '123'], ['currency' => 'regex']],
    'invalid email' => [CompanyResource::class, ['name' => 'Company', 'email' => 'invalid'], ['email' => 'email']],
    'invalid website' => [CompanyResource::class, ['name' => 'Company', 'website' => 'invalid'], ['website' => 'url']],
]);

test('deal and task terminal statuses keep timestamps explicitly editable', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(DealResource::getPages()['create']->getPage())
        ->fillForm(['title' => 'Won sale', 'stage' => DealStage::Won->value, 'amount' => '1234.56', 'currency' => 'lkr', 'expected_close_date' => '2026-11-01', 'closed_at' => '2026-11-02 10:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('deals', ['title' => 'Won sale', 'stage' => 'won', 'amount' => '1234.56', 'currency' => 'LKR', 'expected_close_date' => '2026-11-01 00:00:00', 'closed_at' => '2026-11-02 10:30:00']);

    Livewire::test(TaskResource::getPages()['create']->getPage())
        ->fillForm(['title' => 'Done task', 'status' => TaskStatus::Completed->value, 'completed_at' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tasks', ['title' => 'Done task', 'status' => 'completed', 'completed_at' => null, 'taskable_type' => null, 'taskable_id' => null]);
});

test('deleted companies and contacts cannot be newly selected but existing associations survive edits', function () {
    $this->actingAs(User::factory()->create());
    $company = Company::factory()->create(['name' => 'Archived company']);
    $contact = Contact::factory()->for($company)->create(['first_name' => 'Archived', 'last_name' => 'Contact']);
    $deal = Deal::factory()->for($company)->for($contact)->create();
    $company->delete();
    $contact->delete();

    Livewire::test(DealResource::getPages()['create']->getPage())
        ->assertFormFieldExists('company_id', fn (Select $field): bool => $field->getSearchResults('Archived') === [])
        ->assertFormFieldExists('contact_id', fn (Select $field): bool => $field->getSearchResults('Archived') === [])
        ->fillForm(['title' => 'Invalid sale', 'company_id' => $company->id, 'contact_id' => $contact->id])
        ->call('create')
        ->assertHasFormErrors(['company_id' => 'in', 'contact_id' => 'in']);

    Livewire::test(DealResource::getPages()['edit']->getPage(), ['record' => $deal->id])
        ->assertFormFieldExists('company_id', fn (Select $field): bool => $field->getOptionLabel() === 'Archived company')
        ->assertFormFieldExists('contact_id', fn (Select $field): bool => $field->getOptionLabel() === 'Archived Contact')
        ->fillForm(['title' => 'Updated sale'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('deals', ['id' => $deal->id, 'company_id' => $company->id, 'contact_id' => $contact->id, 'title' => 'Updated sale']);
});

test('deleted polymorphic parents cannot be newly selected but existing links survive edits', function (string $parentClass, string $alias) {
    $this->actingAs(User::factory()->create());
    $parent = $parentClass::factory()->create();
    $note = Note::factory()->for($parent, 'noteable')->create();
    $task = Task::factory()->for($parent, 'taskable')->create();
    $parent->delete();

    Livewire::test(NoteResource::getPages()['create']->getPage())
        ->fillForm(['body' => 'Invalid note', 'noteable_type' => $alias, 'noteable_id' => $parent->id])
        ->call('create')
        ->assertHasFormErrors(['noteable_id' => 'in']);

    Livewire::test(TaskResource::getPages()['create']->getPage())
        ->fillForm(['title' => 'Invalid task', 'taskable_type' => $alias, 'taskable_id' => $parent->id])
        ->call('create')
        ->assertHasFormErrors(['taskable_id' => 'in']);

    Livewire::test(NoteResource::getPages()['edit']->getPage(), ['record' => $note->id])
        ->fillForm(['body' => 'Updated note'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('notes', ['id' => $note->id, 'noteable_type' => $alias, 'noteable_id' => $parent->id, 'body' => 'Updated note']);

    Livewire::test(TaskResource::getPages()['edit']->getPage(), ['record' => $task->id])
        ->fillForm(['title' => 'Updated task'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'taskable_type' => $alias, 'taskable_id' => $parent->id, 'title' => 'Updated task']);
})->with('crm parents');

test('CRM lists search identifying text', function (string $model, string $resource, string $field) {
    $this->actingAs(User::factory()->create());
    $matching = $model::factory()->create([$field => 'Distinctive search target']);
    $other = $model::factory()->create([$field => 'Another record']);

    Livewire::test($resource::getPages()['index']->getPage())
        ->searchTable('Distinctive')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
})->with('crm resources');

test('deals can be filtered by company contact owner and stage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = Company::factory()->create();
    $contact = Contact::factory()->for($company)->create();
    $matching = Deal::factory()->won()->for($company)->for($contact)->for($user, 'owner')->create();
    $other = Deal::factory()->create();

    foreach (['company' => $company->id, 'contact' => $contact->id, 'owner' => $user->id, 'stage' => 'won'] as $filter => $value) {
        Livewire::test(DealResource::getPages()['index']->getPage())
            ->filterTable($filter, $value)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

test('tasks can be filtered by assignee status and related record type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $matching = Task::factory()->completed()->for($user, 'assignedTo')->for(Company::factory(), 'taskable')->create();
    $other = Task::factory()->create();

    foreach (['assignedTo' => $user->id, 'status' => 'completed', 'taskable_type' => 'company'] as $filter => $value) {
        Livewire::test(TaskResource::getPages()['index']->getPage())
            ->filterTable($filter, $value)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

test('notes can be filtered by author and related record type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $matching = Note::factory()->for($user, 'author')->for(Contact::factory(), 'noteable')->create();
    $other = Note::factory()->create();

    foreach (['author' => $user->id, 'noteable_type' => 'contact'] as $filter => $value) {
        Livewire::test(NoteResource::getPages()['index']->getPage())
            ->filterTable($filter, $value)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

test('related labels remain visible in tables after parent deletion', function (string $parentClass, string $alias) {
    $this->actingAs(User::factory()->create());
    $parent = $parentClass::factory()->create();
    $task = Task::factory()->for($parent, 'taskable')->create();
    $note = Note::factory()->for($parent, 'noteable')->create();
    $parent->delete();
    $label = match ($alias) {
        'company' => 'Company: '.$parent->name,
        'contact' => 'Contact: '.trim($parent->first_name.' '.$parent->last_name),
        'deal' => 'Deal: '.$parent->title,
    };

    Livewire::test(TaskResource::getPages()['index']->getPage())
        ->assertCanSeeTableRecords([$task])
        ->assertSee($label);

    Livewire::test(NoteResource::getPages()['index']->getPage())
        ->assertCanSeeTableRecords([$note])
        ->assertSee($label);
})->with('crm parents');

test('note bodies render as escaped plain text', function () {
    $this->actingAs(User::factory()->create());
    $note = Note::factory()->create(['body' => '<script>alert("note")</script>']);

    Livewire::test(NoteResource::getPages()['index']->getPage())
        ->assertCanSeeTableRecords([$note])
        ->assertSee($note->body)
        ->assertDontSee($note->body, escape: false);
});

test('table row actions soft delete and restore records', function () {
    $this->actingAs(User::factory()->create());
    $company = Company::factory()->create();
    $page = CompanyResource::getPages()['index']->getPage();

    Livewire::test($page)
        ->callAction(TestAction::make('delete')->table($company));

    $this->assertSoftDeleted($company);

    Livewire::test($page)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('restore')->table($company));

    $this->assertNotSoftDeleted($company);
});
