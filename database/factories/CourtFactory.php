<?php

namespace Database\Factories;

use App\Models\Court;
use App\Models\CourtType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Court>
 */
class CourtFactory extends Factory
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
            // Self-sufficient: creates its own CourtType if none is
            // supplied, instead of erroring against an empty table in a
            // fresh test database (Collection::random() throws on empty).
            'type' => CourtType::factory(),
        ];
    }
}
