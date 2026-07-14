<?php

namespace Database\Factories;

use App\Models\InvoiceDistribution;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceDistributionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = InvoiceDistribution::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Define default attributes for the InvoiceDistribution model here
            'invoice_id' => \App\Models\Invoice::factory(),
            'amount' => $this->faker->numberBetween(5000000, 500000000),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
