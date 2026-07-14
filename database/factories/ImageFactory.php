<?php

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Image::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Replace 'url' with 'path' to match the database schema
            'path' => $this->faker->imageUrl(),
            'imageable_id' => $this->faker->numberBetween(1, 100),
            'imageable_type' => 'App\Models\Invoice',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
