<?php

namespace Tests\Unit\Models;

use App\Enums\PetSize;
use App\Models\Service;
use App\Models\ServiceCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    public static function drawings(): array
    {
        return [
            'bath' => ['基礎洗澡', null, 'bath'],
            'the name beats the category' => ['基礎洗澡', '洗澡美容', 'bath'],
            'grooming' => ['洗澡＋全身美容', null, 'scissors'],
            'trim' => ['局部修剪', null, 'scissors'],
            'styling' => ['造型設計', null, 'scissors'],
            'spa' => ['藥浴 SPA', null, 'spa'],
            'spa in lower case' => ['spa 保濕', null, 'spa'],
            'care' => ['皮毛護理', null, 'spa'],
            'cats come first' => ['貓咪洗澡', null, 'cat'],
            'falls back to the category' => ['加值方案A', '貓咪專區', 'cat'],
            'nail trims count as cutting' => ['指甲修剪', null, 'scissors'],
            'unknown everywhere' => ['耳朵清潔', '其他', 'bath'],
        ];
    }

    #[DataProvider('drawings')]
    public function test_each_service_gets_a_fitting_drawing(string $name, ?string $category, string $art): void
    {
        $service = new Service(['name' => $name]);
        $service->setRelation('category', $category ? new ServiceCategory(['name' => $category]) : null);

        $this->assertSame($art, $service->artName());
    }

    public function test_the_price_follows_the_size_and_defaults_to_small(): void
    {
        $service = new Service(['price_small' => 500, 'price_medium' => 700, 'price_large' => 1000]);

        $this->assertSame(500, $service->priceFor(PetSize::Small));
        $this->assertSame(700, $service->priceFor(PetSize::Medium));
        $this->assertSame(1000, $service->priceFor(PetSize::Large));
        $this->assertSame(500, $service->priceFor(null), 'size unknown: charge the small price');
    }
}
