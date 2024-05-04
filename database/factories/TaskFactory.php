<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        // obtain all the users in the database with a role that does not have the word 'admin' in them
        $assignedToArray = \App\Models\User::query()
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'admin');
            })->pluck('id')->toArray();

        return [
            //
            'description' => $this->faker->sentence(),
            'title' => $this->faker->sentence(),
            'assigned_to' => $this->faker->randomElement($assignedToArray),
            'due_date' => $this->faker->dateTime(),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high']),
        ];
    }
}
