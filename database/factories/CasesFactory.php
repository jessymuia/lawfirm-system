<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cases>
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
            'case_number' => 'CN' . $this->faker->randomNumber(5),
            'description' => $this->faker->sentence(),
            'client_id' => \App\Models\Client::query()->inRandomOrder()->first()->id,
            'assigned_to' => \App\Models\User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'advocate');
                })
                ->inRandomOrder()->first()->id,
            'start_date' => $start_date,
            'end_date' => $end_date > now() ? null : $end_date,
            'case_type' => $this->faker->randomElement([
                'civil', 'criminal', 'family', 'land', 'commercial', 'constitutional',
                'labour and employment', 'administrative', 'appellate', 'election petitions'
            ]),
            'police_station' => $this->faker->randomElement([
                'Kilimani', 'Central', 'Kasarani', 'Kileleshwa', 'Langata', 'Kibera',
                'Meru', 'Nakuru', 'Mombasa', 'Kisumu', 'Eldoret', 'Kakamega', 'Kisii',
                'Syokimau', 'Kitengela', 'Ruiru', 'Thika', 'Kiambu', 'Kikuyu', 'Kericho'
            ]),
            'court_id' => \App\Models\Court::query()->inRandomOrder()->first()->id,
            'opposing_party' => $this->faker->name()
        ];
    }
}
