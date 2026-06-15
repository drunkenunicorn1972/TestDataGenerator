<?php declare(strict_types=1);

namespace TestDataGenerator\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TestDataGenerator\Service\DataImporter;

#[AsMessageHandler]
class GenerateTestDataHandler
{
    private DataImporter $dataImporter;
    private SystemConfigService $systemConfigService;

    public function __construct(DataImporter $dataImporter, SystemConfigService $systemConfigService)
    {
        $this->dataImporter = $dataImporter;
        $this->systemConfigService = $systemConfigService;
    }

    public function __invoke(GenerateTestDataMessage $message): void
    {
        $this->systemConfigService->set('TestDataGenerator.config.status', 'running');

        try {
            $context = Context::createDefaultContext();
            $this->dataImporter->importData(
                $message->getCategoriesCount(),
                $message->getProductsCount(),
                $message->isGenerateImages(),
                $message->isUseExistingCategories(),
                $message->isCreateTranslationsOnly(),
                $context,
                $message->getSelectedCategoryId(),
                $message->isDeleteTestDataBeforeGeneration(),
                $message->isGenerateReviews()
            );

            $this->systemConfigService->set('TestDataGenerator.config.status', 'success');
        } catch (\Throwable $e) {
            $this->systemConfigService->set('TestDataGenerator.config.status', 'failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
