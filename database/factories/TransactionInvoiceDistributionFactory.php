<?php

namespace Database\Factories;

use App\Models\TransactionInvoiceDistribution;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionInvoiceDistributionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TransactionInvoiceDistribution::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Define default attributes for the TransactionInvoiceDistribution model here
            'transaction_id' => \App\Models\Transaction::factory(),
            'invoice_distribution_id' => \App\Models\InvoiceDistribution::factory(),
            'amount' => $this->faker->numberBetween(5000000, 500000000),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
