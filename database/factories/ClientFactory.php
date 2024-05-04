<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
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
        // fetch random advocate id from present advocates
        $advocateIds = User::query()->whereHas('roles', function ($query) {
            $query->where('name', 'advocate');
        })->pluck('id')->toArray();
        return [
            //
            "name" => $this->faker->name(),
            "phone_number" => $this->faker->phoneNumber(),
            "extra_phone_number" => $this->faker->phoneNumber(),
            "address" => $this->faker->address(),
            "advocate_id" => $this->faker->randomElement($advocateIds),
        ];
    }
}
