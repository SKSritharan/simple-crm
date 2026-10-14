<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Note> */
class NoteFactory extends Factory
{
    /** @return array{body: string, noteable_type: string, noteable_id: CompanyFactory} */
    public function definition(): array
    {
        return [
            'body' => fake()->paragraph(),
            'noteable_type' => 'company',
            'noteable_id' => Company::factory(),
        ];
    }
}
