<?php declare(strict_types=1);

namespace TestDataGenerator\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class GenerateTestDataMessage implements AsyncMessageInterface
{
    private int $categoriesCount;
    private int $productsCount;
    private bool $generateImages;
    private bool $useExistingCategories;
    private bool $createTranslationsOnly;

    public function __construct(int $categoriesCount, int $productsCount, bool $generateImages, bool $useExistingCategories, bool $createTranslationsOnly = false)
    {
        $this->categoriesCount = $categoriesCount;
        $this->productsCount = $productsCount;
        $this->generateImages = $generateImages;
        $this->useExistingCategories = $useExistingCategories;
        $this->createTranslationsOnly = $createTranslationsOnly;
    }

    public function getCategoriesCount(): int
    {
        return $this->categoriesCount;
    }

    public function getProductsCount(): int
    {
        return $this->productsCount;
    }

    public function isGenerateImages(): bool
    {
        return $this->generateImages;
    }

    public function isUseExistingCategories(): bool
    {
        return $this->useExistingCategories;
    }

    public function isCreateTranslationsOnly(): bool
    {
        return $this->createTranslationsOnly;
    }
}
