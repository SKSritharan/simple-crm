<?php

use App\Enums\DealStage;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\CrmDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('crm records expose company contact and user relationships', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->for($owner, 'owner')->create();
    $contact = Contact::factory()->for($company)->for($owner, 'owner')->create();
    $deal = Deal::factory()->for($company)->for($contact)->for($owner, 'owner')->create();
    $task = Task::factory()->for($owner, 'assignedTo')->create();
    $note = Note::factory()->for($company, 'noteable')->for($owner, 'author')->create();

    expect($company->contacts->sole()->is($contact))->toBeTrue();
    expect($company->deals->sole()->is($deal))->toBeTrue();
    expect($contact->company->is($company))->toBeTrue();
    expect($contact->deals->sole()->is($deal))->toBeTrue();
    expect($deal->company->is($company))->toBeTrue();
    expect($deal->contact->is($contact))->toBeTrue();
    expect($company->owner->is($owner))->toBeTrue();
    expect($contact->owner->is($owner))->toBeTrue();
    expect($deal->owner->is($owner))->toBeTrue();
    expect($task->assignedTo->is($owner))->toBeTrue();
    expect($note->author->is($owner))->toBeTrue();
    expect($owner->ownedCompanies->sole()->is($company))->toBeTrue();
    expect($owner->ownedContacts->sole()->is($contact))->toBeTrue();
    expect($owner->ownedDeals->sole()->is($deal))->toBeTrue();
    expect($owner->assignedTasks->sole()->is($task))->toBeTrue();
    expect($owner->authoredNotes->sole()->is($note))->toBeTrue();
});

test('contacts deals and tasks can exist independently', function () {
    $contact = Contact::factory()->create();
    $deal = Deal::factory()->create();
    $task = Task::factory()->create();

    expect($contact->company)->toBeNull();
    expect($deal->company)->toBeNull();
    expect($deal->contact)->toBeNull();
    expect($task->taskable)->toBeNull();
    expect(User::count())->toBe(0);
    expect(Company::count())->toBe(0);
});

test('notes and tasks resolve stable parent aliases', function (string $parentClass, string $alias) {
    $parent = $parentClass::factory()->create();

    $note = Note::factory()->for($parent, 'noteable')->create();
    $task = Task::factory()->for($parent, 'taskable')->create();

    expect($note->fresh()->noteable->is($parent))->toBeTrue();
    expect($task->fresh()->taskable->is($parent))->toBeTrue();
    expect($parent->notes->sole()->is($note))->toBeTrue();
    expect($parent->tasks->sole()->is($task))->toBeTrue();
    $this->assertDatabaseHas('notes', ['id' => $note->id, 'noteable_type' => $alias]);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'taskable_type' => $alias]);
})->with([
    'company' => [Company::class, 'company'],
    'contact' => [Contact::class, 'contact'],
    'deal' => [Deal::class, 'deal'],
]);

test('database defaults and casts round trip', function () {
    $deal = Deal::query()->create(['title' => 'Default deal'])->fresh();
    $task = Task::query()->create(['title' => 'Default task'])->fresh();

    expect($deal->stage)->toBe(DealStage::New);
    expect($deal->amount)->toBe('0.00');
    expect($deal->currency)->toBe('USD');
    expect($task->status)->toBe(TaskStatus::Pending);
});

test('decimal amounts currencies and dates survive persistence', function () {
    $deal = Deal::factory()->create([
        'amount' => '123456.78',
        'currency' => 'LKR',
        'expected_close_date' => '2026-11-01',
        'closed_at' => '2026-11-02 10:30:00',
    ]);
    $task = Task::factory()->completed()->create([
        'due_at' => '2026-11-01 09:00:00',
        'completed_at' => '2026-11-01 08:00:00',
    ]);

    expect($deal->fresh()->amount)->toBe('123456.78');
    expect($deal->currency)->toBe('LKR');
    expect($deal->expected_close_date->toDateString())->toBe('2026-11-01');
    expect($deal->closed_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 10:30:00');
    expect($task->fresh()->due_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 09:00:00');
    expect($task->completed_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 08:00:00');
});

test('all sales stages round trip through the enum cast', function (DealStage $stage) {
    $deal = Deal::factory()->create(['stage' => $stage]);

    expect($deal->fresh()->stage)->toBe($stage);
})->with(DealStage::cases());

test('all task statuses round trip through the enum cast', function (TaskStatus $status) {
    $task = Task::factory()->create(['status' => $status]);

    expect($task->fresh()->status)->toBe($status);
})->with(TaskStatus::cases());

test('terminal factory states produce consistent timestamps', function () {
    $this->freezeSecond();

    $won = Deal::factory()->won()->create();
    $lost = Deal::factory()->lost()->create();
    $completed = Task::factory()->completed()->create();
    $cancelled = Task::factory()->cancelled()->create();

    expect($won->stage)->toBe(DealStage::Won);
    expect($won->closed_at->equalTo(now()))->toBeTrue();
    expect($won->lost_reason)->toBeNull();
    expect($lost->stage)->toBe(DealStage::Lost);
    expect($lost->closed_at->equalTo(now()))->toBeTrue();
    expect($lost->lost_reason)->toBe('Budget unavailable');
    expect($completed->status)->toBe(TaskStatus::Completed);
    expect($completed->completed_at->equalTo(now()))->toBeTrue();
    expect($cancelled->status)->toBe(TaskStatus::Cancelled);
    expect($cancelled->completed_at)->toBeNull();
});

test('crm records can be soft deleted and restored', function (string $modelClass) {
    $record = $modelClass::factory()->create();

    $record->delete();

    $this->assertSoftDeleted($record);
    expect($modelClass::find($record->id))->toBeNull();

    $record->restore();

    expect($modelClass::find($record->id)->is($record))->toBeTrue();
})->with([Company::class, Contact::class, Deal::class, Task::class, Note::class]);

test('soft deleting parents preserves sales records and history', function (string $parentClass) {
    $company = Company::factory()->create();
    $contact = Contact::factory()->for($company)->create();
    $deal = Deal::factory()->for($company)->for($contact)->create();
    $parent = collect([$company, $contact, $deal])->first(fn ($record) => $record instanceof $parentClass);
    $note = Note::factory()->for($parent, 'noteable')->create();
    $task = Task::factory()->for($parent, 'taskable')->create();

    $parent->delete();

    $this->assertModelExists($note);
    $this->assertModelExists($task);
    expect($note->fresh()->deleted_at)->toBeNull();
    expect($task->fresh()->deleted_at)->toBeNull();
    expect($company->fresh()->deleted_at !== null)->toBe($parentClass === Company::class);
    expect($contact->fresh()->deleted_at !== null)->toBe($parentClass === Contact::class);
    expect($deal->fresh()->deleted_at !== null)->toBe($parentClass === Deal::class);
    expect($deal->fresh()->company_id)->toBe($company->id);
    expect($deal->fresh()->contact_id)->toBe($contact->id);
    $parent->restore();
    expect($note->fresh()->noteable->is($parent))->toBeTrue();
})->with([Company::class, Contact::class, Deal::class]);

test('permanent deletion is blocked by related sales records', function (string $parentClass, string $childClass, string $relation) {
    $parent = $parentClass::factory()->create();
    $child = $childClass::factory()->for($parent, $relation)->create();

    expect(fn () => $parent->forceDelete())->toThrow(QueryException::class);

    $this->assertModelExists($parent);
    $this->assertModelExists($child);
})->with([
    'company contacts' => [Company::class, Contact::class, 'company'],
    'company deals' => [Company::class, Deal::class, 'company'],
    'contact deals' => [Contact::class, Deal::class, 'contact'],
]);

test('deleting a user clears ownership assignment and authorship without deleting crm data', function () {
    $user = User::factory()->create();
    $company = Company::factory()->for($user, 'owner')->create();
    $contact = Contact::factory()->for($user, 'owner')->create();
    $deal = Deal::factory()->for($user, 'owner')->create();
    $task = Task::factory()->for($user, 'assignedTo')->create();
    $note = Note::factory()->for($user, 'author')->create();

    $user->delete();

    expect($company->fresh()->owner_id)->toBeNull();
    expect($contact->fresh()->owner_id)->toBeNull();
    expect($deal->fresh()->owner_id)->toBeNull();
    expect($task->fresh()->assigned_to_id)->toBeNull();
    expect($note->fresh()->author_id)->toBeNull();
});

test('moving a contact does not rewrite a deals company', function () {
    $original = Company::factory()->create();
    $replacement = Company::factory()->create();
    $contact = Contact::factory()->for($original)->create();
    $deal = Deal::factory()->for($original, 'company')->for($contact)->create();

    $contact->company()->associate($replacement);
    $contact->save();

    expect($contact->fresh()->company_id)->toBe($replacement->id);
    expect($deal->fresh()->company_id)->toBe($original->id);
});

test('company names and contact emails allow duplicates', function () {
    Company::factory()->count(2)->create(['name' => 'Shared name']);
    Contact::factory()->count(2)->create(['email' => 'shared@example.test']);

    expect(Company::where('name', 'Shared name')->count())->toBe(2);
    expect(Contact::where('email', 'shared@example.test')->count())->toBe(2);
});

test('demo seeding creates linked crm records without creating users', function (bool $withOwner) {
    $owner = $withOwner ? User::factory()->create() : null;
    $this->freezeSecond();

    $this->seed(CrmDemoSeeder::class);

    expect(User::count())->toBe($withOwner ? 1 : 0);
    expect(Company::count())->toBe(3);
    expect(Contact::count())->toBe(3);
    expect(Deal::count())->toBe(3);
    expect(Task::count())->toBe(3);
    expect(Note::count())->toBe(3);
    foreach (Deal::with(['company', 'contact', 'tasks', 'notes'])->get() as $deal) {
        expect($deal->owner_id)->toBe($owner?->id);
        expect($deal->contact->company_id)->toBe($deal->company_id);
        expect($deal->company->owner_id)->toBe($owner?->id);
        expect($deal->contact->owner_id)->toBe($owner?->id);
        expect($deal->tasks->sole()->assigned_to_id)->toBe($owner?->id);
        expect($deal->tasks->sole()->due_at->equalTo(now()->addWeek()))->toBeTrue();
        expect($deal->notes->sole()->author_id)->toBe($owner?->id);
    }
})->with(['without users' => false, 'with owner' => true]);
