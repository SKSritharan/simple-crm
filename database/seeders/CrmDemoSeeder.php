<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

class CrmDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::query()->first();

        Company::factory()->count(3)->create(['owner_id' => $owner?->id])->each(function (Company $company) use ($owner): void {
            $contact = Contact::factory()->for($company)->create(['owner_id' => $owner?->id]);
            $deal = Deal::factory()->for($company)->for($contact)->create(['owner_id' => $owner?->id]);

            Task::factory()->for($deal, 'taskable')->create([
                'assigned_to_id' => $owner?->id,
                'due_at' => now()->addWeek(),
            ]);
            Note::factory()->for($deal, 'noteable')->create(['author_id' => $owner?->id]);
        });
    }
}
