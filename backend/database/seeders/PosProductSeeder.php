<?php

namespace Database\Seeders;

use App\Models\PosProduct;
use Illuminate\Database\Seeder;

class PosProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['sku' => 'TEA-001', 'name' => 'Chai', 'price' => 80],
            ['sku' => 'PARA-001', 'name' => 'Paratha', 'price' => 120],
            ['sku' => 'EGG-001', 'name' => 'Anda', 'price' => 40],
            ['sku' => 'WATER-500', 'name' => 'Mineral Water 500ml', 'price' => 60],
            ['sku' => 'BISC-001', 'name' => 'Biscuit Pack', 'price' => 50],
            ['sku' => 'MILK-001', 'name' => 'Milk 1L', 'price' => 280],
            ['sku' => 'BREAD-001', 'name' => 'Bread Loaf', 'price' => 150],
            ['sku' => 'RICE-001', 'name' => 'Basmati Rice 1kg', 'price' => 450],
        ];

        foreach ($products as $product) {
            PosProduct::updateOrCreate(
                ['sku' => $product['sku']],
                ['name' => $product['name'], 'price' => $product['price'], 'is_active' => true]
            );
        }
    }
}
