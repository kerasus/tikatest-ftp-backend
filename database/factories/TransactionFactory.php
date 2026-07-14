<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'amount' => $this->faker->numberBetween(5000000, 500000000),
            'receipt_image' => $this->faker->optional()->imageUrl(),
            'paid_at' => $this->faker->optional()->dateTimeThisMonth(),
            'authority' => $this->faker->optional()->uuid(),
            'transactionID' => $this->faker->optional()->uuid(),
            'payment_method' => $this->faker->randomElement([
                'bank_gateway',
                'mobile_banking',
                'atm',
                'cash',
                'check'
            ]),
            'transaction_status' => $this->faker->randomElement([
                'transferred_to_pay',
                'unsuccessful',
                'paid',
                'unpaid'
            ]),
        ];
    }
}
