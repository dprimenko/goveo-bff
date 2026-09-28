<?php

declare(strict_types=1);

namespace App\Tests\Products;

use App\Products\Domain\Product;
use PHPUnit\Framework\TestCase;

final class PlaceholderImageTest extends TestCase
{
    private const PLACEHOLDER = 'https://goveo.b-cdn.net/resources/producto.jpg';

    public function testAProductWithoutImagesGetsIt(): void
    {
        $product = $this->product();

        self::assertTrue($product->setPlaceholderImage(self::PLACEHOLDER));
        self::assertSame([self::PLACEHOLDER], $product->imageUrls());
        self::assertTrue($product->isPlaceholderImage(self::PLACEHOLDER));
    }

    public function testAProductWithImagesKeepsThem(): void
    {
        $product = $this->product();
        $product->addImage('https://goveo.b-cdn.net/business/b/products/p/0.jpg');

        self::assertFalse($product->setPlaceholderImage(self::PLACEHOLDER));
        self::assertSame(['https://goveo.b-cdn.net/business/b/products/p/0.jpg'], $product->imageUrls());
    }

    public function testARealImageReplacesThePlaceholder(): void
    {
        $product = $this->product();
        $product->setPlaceholderImage(self::PLACEHOLDER);

        $product->addImage('https://goveo.b-cdn.net/business/b/products/p/0.jpg');

        self::assertSame(['https://goveo.b-cdn.net/business/b/products/p/0.jpg'], $product->imageUrls());
    }

    public function testARealImageIsNotAPlaceholder(): void
    {
        $product = $this->product();
        $product->addImage('https://goveo.b-cdn.net/business/b/products/p/0.jpg');

        self::assertFalse($product->isPlaceholderImage('https://goveo.b-cdn.net/business/b/products/p/0.jpg'));
    }

    private function product(): Product
    {
        return new Product('p', 'b', 'Hamburguesa', 'hamburguesa');
    }
}
