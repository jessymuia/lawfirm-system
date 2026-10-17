<?php

namespace Database\Factories;

use App\Models\Cases;
use App\Models\Court;
use App\Models\Hearing;
use App\Models\HearingType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hearing>
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
        // Self-sufficient: creates its own dependencies if none exist,
        // instead of erroring against ->first()->id being null in a
        // fresh test database (see the same fix in CasesFactory/CourtFactory).
        return [
            //
            'case_id' => function () {
                return Cases::query()->inRandomOrder()->first()?->id ?? Cases::factory()->create()->id;
            },
            'court_id' => function () {
                return Court::query()->inRandomOrder()->first()?->id ?? Court::factory()->create()->id;
            },
            'hearing_date' => $this->faker->dateTime(),
            'hearing_type' => function () {
                return HearingType::query()->inRandomOrder()->first()?->id ?? HearingType::factory()->create()->id;
            },
            'notes' => $this->faker->sentence(),
            'outcome' => $this->faker->sentence(),
        ];
    }
}
