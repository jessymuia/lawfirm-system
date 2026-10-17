<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            //
            'name' => $this->faker->name(),
            'phone_number' => $this->faker->unique()->numerify('07########'),
            'extra_phone_number' => $this->faker->numerify('07########'),
            'address' => $this->faker->address(),
            // Self-sufficient: creates its own advocate user if none
            // exists, instead of erroring against Faker::randomElement([])
            // in a fresh test database with no seeded advocates.
            'advocate_id' => function () {
                $advocateIds = User::query()->whereHas('roles', function ($query) {
                    $query->where('name', 'advocate');
                })->pluck('id')->toArray();

                if (! empty($advocateIds)) {
                    return $this->faker->randomElement($advocateIds);
                }

                $advocate = User::factory()->create();
                $role = Role::findOrCreate('advocate', 'web');
                $advocate->assignRole($role);

                return $advocate->id;
            },
        ];
    }
}
