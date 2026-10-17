<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
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
        // Self-sufficient: creates its own assignable user if none
        // exists, instead of erroring against Faker::randomElement([])
        // in a fresh test database.
        return [
            //
            'description' => $this->faker->sentence(),
            'title' => $this->faker->sentence(),
            'assigned_to' => function () {
                $assignedToArray = User::query()
                    ->whereDoesntHave('roles', function ($query) {
                        $query->where('name', 'admin');
                    })->pluck('id')->toArray();

                if (! empty($assignedToArray)) {
                    return $this->faker->randomElement($assignedToArray);
                }

                return User::factory()->create()->id;
            },
            'due_date' => $this->faker->dateTime(),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high']),
        ];
    }
}
