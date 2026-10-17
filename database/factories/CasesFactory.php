<?php

namespace Database\Factories;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Cases>
 */
class CasesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $start_date = $this->faker->dateTime();
        $end_date = $this->faker->dateTimeBetween($start_date, '+1 year');

        return [
            //
            'case_number' => 'CN'.$this->faker->unique()->randomNumber(6),
            'description' => $this->faker->sentence(),
            // Self-sufficient: each dependency creates its own related
            // record if none exists, instead of erroring against
            // ->first()->id on an empty query result (null) in a fresh
            // test database.
            'client_id' => function () {
                return Client::query()->inRandomOrder()->first()?->id
                    ?? Client::factory()->create()->id;
            },
            'assigned_to' => function () {
                $advocate = User::query()
                    ->whereHas('roles', function ($query) {
                        $query->where('name', 'advocate');
                    })
                    ->inRandomOrder()->first();

                if ($advocate) {
                    return $advocate->id;
                }

                $advocate = User::factory()->create();
                $role = Role::findOrCreate('advocate', 'web');
                $advocate->assignRole($role);

                return $advocate->id;
            },
            'start_date' => $start_date,
            'end_date' => $end_date > now() ? null : $end_date,
            'case_type' => $this->faker->randomElement([
                'civil', 'criminal', 'family', 'land', 'commercial', 'constitutional',
                'labour and employment', 'administrative', 'appellate', 'election petitions',
            ]),
            'police_station' => $this->faker->randomElement([
                'Kilimani', 'Central', 'Kasarani', 'Kileleshwa', 'Langata', 'Kibera',
                'Meru', 'Nakuru', 'Mombasa', 'Kisumu', 'Eldoret', 'Kakamega', 'Kisii',
                'Syokimau', 'Kitengela', 'Ruiru', 'Thika', 'Kiambu', 'Kikuyu', 'Kericho',
            ]),
            'court_id' => function () {
                return Court::query()->inRandomOrder()->first()?->id
                    ?? Court::factory()->create()->id;
            },
            'opposing_party' => $this->faker->name(),
        ];
    }
}
