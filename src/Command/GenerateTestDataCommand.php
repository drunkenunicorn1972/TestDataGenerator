<?php declare(strict_types=1);

namespace TestDataGenerator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\Context;
use TestDataGenerator\Service\DataImporter;

#[AsCommand(
    name: 'test-data:generate',
    description: 'Generate test data'
)]
class GenerateTestDataCommand extends Command
{
    private DataImporter $dataImporter;

    public function __construct(DataImporter $dataImporter)
    {
        parent::__construct();
        $this->dataImporter = $dataImporter;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting test data generation...');
        $context = Context::createDefaultContext();
        
        $this->dataImporter->importData(
            categoriesCount: 1,
            productsCount: 2,
            generateImages: true,
            useExistingCategories: false,
            createTranslationsOnly: false,
            context: $context
        );

        $output->writeln('Finished test data generation!');
        return Command::SUCCESS;
    }
}
