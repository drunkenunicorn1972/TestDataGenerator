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

    protected function configure(): void
    {
        $this->addOption('reviews', 'r', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Generate product reviews');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting test data generation...');
        $context = Context::createDefaultContext();
        
        $generateReviews = (bool) $input->getOption('reviews');

        $this->dataImporter->importData(
            categoriesCount: 1,
            productsCount: 2,
            generateImages: true,
            useExistingCategories: false,
            createTranslationsOnly: false,
            context: $context,
            generateReviews: $generateReviews
        );

        $output->writeln('Finished test data generation!');
        return Command::SUCCESS;
    }
}
