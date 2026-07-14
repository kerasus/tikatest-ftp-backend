<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_number' => $this->faker->numerify('###'),
            'type' => $this->faker->randomElement(['residential', 'commercial']),
            'area' => $this->faker->randomFloat(2, 50, 200),
            'floor' => $this->faker->numberBetween(1, 10),
            'number_of_rooms' => $this->faker->numberBetween(1, 5),
            'number_of_residents' => $this->faker->numberBetween(1, 5),
            'parking_spaces' => $this->faker->numberBetween(0, 2),
            'resident_name' => $this->faker->optional()->name(),
            'resident_phone' => $this->faker->optional()->phoneNumber(),
            'owner_name' => $this->faker->optional()->name(),
            'owner_phone' => $this->faker->optional()->phoneNumber(),
        ];
    }
}
