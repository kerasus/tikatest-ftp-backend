<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['آب', 'شارژ ماهیانه', 'آسانسور']),
            'description' => $this->faker->sentence(),
        ];
    }
}
