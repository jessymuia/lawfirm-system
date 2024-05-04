<?php

namespace Database\Factories;

use App\Models\Cases;
use App\Models\Court;
use App\Models\HearingType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hearing>
 */
class HearingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        // select a random case id
        return [
            //
            'case_id' => Cases::query()->inRandomOrder()->first()->id,
            'court_id' => Court::query()->inRandomOrder()->first()->id,
            'hearing_date' => $this->faker->dateTime(),
            'hearing_type' => HearingType::query()->inRandomOrder()->first()->id,
            'notes' => $this->faker->sentence(),
            'outcome' => $this->faker->sentence()
        ];
    }
}
