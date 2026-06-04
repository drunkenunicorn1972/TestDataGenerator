<?php declare(strict_types=1);

namespace TestDataGenerator\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Psr\Log\LoggerInterface;

class DataImporter
{
    private EntityRepository $categoryRepository;
    private EntityRepository $productRepository;
    private EntityRepository $propertyGroupRepository;
    private EntityRepository $propertyGroupOptionRepository;
    private EntityRepository $mediaRepository;
    private EntityRepository $mediaFolderRepository;
    private EntityRepository $productMediaRepository;
    private EntityRepository $taxRepository;
    private EntityRepository $salesChannelRepository;
    private FileSaver $fileSaver;
    private GeminiClient $geminiClient;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $categoryRepository,
        EntityRepository $productRepository,
        EntityRepository $propertyGroupRepository,
        EntityRepository $propertyGroupOptionRepository,
        EntityRepository $mediaRepository,
        EntityRepository $mediaFolderRepository,
        EntityRepository $productMediaRepository,
        EntityRepository $taxRepository,
        EntityRepository $salesChannelRepository,
        FileSaver $fileSaver,
        GeminiClient $geminiClient,
        LoggerInterface $logger
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->propertyGroupRepository = $propertyGroupRepository;
        $this->propertyGroupOptionRepository = $propertyGroupOptionRepository;
        $this->mediaRepository = $mediaRepository;
        $this->mediaFolderRepository = $mediaFolderRepository;
        $this->productMediaRepository = $productMediaRepository;
        $this->taxRepository = $taxRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->fileSaver = $fileSaver;
        $this->geminiClient = $geminiClient;
        $this->logger = $logger;
    }

    public function importData(int $categoriesCount, int $productsCount, bool $generateImages, bool $useExistingCategories, bool $createTranslationsOnly, Context $context): void
    {
        // 1. Resolve default navigation category (root category of the first active Sales Channel)
        $rootCategoryId = $this->resolveNavigationRootCategoryId($context);

        // 2. Resolve active sales channels for product visibilities
        $visibilities = $this->resolveProductVisibilities($context);

        // 3. Resolve tax rate
        $tax = $this->resolveTax($context);
        $taxId = $tax['id'];
        $taxRateValue = $tax['rate'];

        // 4. Resolve product media folder
        $mediaFolderId = $this->resolveProductMediaFolderId($context);

        // 5. Resolve active languages
        $activeLanguages = $this->resolveSalesChannelLanguages($context);
        $defaultLangId = array_key_first($activeLanguages);

        if ($createTranslationsOnly) {
            $this->generateMissingTranslations($activeLanguages, $context);
            return;
        }

        $categories = [];
        if ($useExistingCategories) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $allActiveCategories = $this->categoryRepository->search($criteria, $context);

            foreach ($allActiveCategories as $category) {
                // Exclude root navigation category and any category without parent (system categories)
                if ($category->getId() === $rootCategoryId || $category->getParentId() === null) {
                    continue;
                }
                $categories[] = [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'description' => $category->getDescription(),
                ];
            }

            if (empty($categories)) {
                throw new \Exception('No existing categories found to add products to. Please generate categories first.');
            }

            $categoriesCount = count($categories);
        } else {
            // Generate categories structure using Gemini with translations
            $translationsProperties = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translationsProperties[$localeCode] = [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING']
                    ],
                    'required' => ['name', 'description']
                ];
            }

            $categorySchema = [
                'type' => 'OBJECT',
                'properties' => [
                    'categories' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'translations' => [
                                    'type' => 'OBJECT',
                                    'properties' => $translationsProperties,
                                    'required' => array_values($activeLanguages)
                                ]
                            ],
                            'required' => ['translations']
                        ]
                    ]
                ],
                'required' => ['categories']
            ];

            $localesList = implode(', ', array_values($activeLanguages));
            $categoriesPrompt = sprintf(
                "We are setting up a demo store. Generate exactly %d categories for our store.
                For each category, you must provide the name and description translated into all of the following locales: %s.
                Ensure translations are high quality, natural, and accurately reflect the same category name and description in each language.",
                $categoriesCount,
                $localesList
            );

            $jsonText = $this->geminiClient->generateText($categoriesPrompt, $categorySchema);
            $catData = json_decode($jsonText, true);

            if (!isset($catData['categories']) || !is_array($catData['categories'])) {
                throw new \Exception('Gemini returned an invalid category response.');
            }

            foreach ($catData['categories'] as $catEntry) {
                $categoryId = Uuid::randomHex();

                $translationsPayload = [];
                foreach ($activeLanguages as $langId => $localeCode) {
                    if (isset($catEntry['translations'][$localeCode])) {
                        $translationsPayload[$langId] = [
                            'name' => $catEntry['translations'][$localeCode]['name'],
                            'description' => $catEntry['translations'][$localeCode]['description'],
                        ];
                    }
                }

                $categoryPayload = [
                    'id' => $categoryId,
                    'translations' => $translationsPayload,
                    'active' => true,
                ];

                if ($defaultLangId && isset($translationsPayload[$defaultLangId])) {
                    $categoryPayload['name'] = $translationsPayload[$defaultLangId]['name'];
                    $categoryPayload['description'] = $translationsPayload[$defaultLangId]['description'];
                }

                if ($rootCategoryId) {
                    $categoryPayload['parentId'] = $rootCategoryId;
                }

                $this->categoryRepository->create([$categoryPayload], $context);

                $defaultName = '';
                $defaultDesc = '';
                if ($defaultLangId && isset($translationsPayload[$defaultLangId])) {
                    $defaultName = $translationsPayload[$defaultLangId]['name'];
                    $defaultDesc = $translationsPayload[$defaultLangId]['description'];
                } else {
                    $firstTrans = reset($translationsPayload);
                    if ($firstTrans) {
                        $defaultName = $firstTrans['name'];
                        $defaultDesc = $firstTrans['description'];
                    }
                }

                $categories[] = [
                    'id' => $categoryId,
                    'name' => $defaultName,
                    'description' => $defaultDesc,
                ];
            }
        }

        // Calculate division of products
        $productsPerCategory = (int) floor($productsCount / $categoriesCount);
        if ($productsPerCategory < 1) {
            $productsPerCategory = 1;
        }
        $lastCategoryProducts = $productsCount - ($productsPerCategory * ($categoriesCount - 1));
        if ($lastCategoryProducts < 1) {
            $lastCategoryProducts = 1;
        }

        // Define schema for single-category products generation with translations
        $translationsProperties = [];
        foreach ($activeLanguages as $langId => $localeCode) {
            $translationsProperties[$localeCode] = [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING']
                ],
                'required' => ['name', 'description']
            ];
        }

        $productSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'products' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'translations' => [
                                'type' => 'OBJECT',
                                'properties' => $translationsProperties,
                                'required' => array_values($activeLanguages)
                            ],
                            'price' => ['type' => 'NUMBER'],
                            'stock' => ['type' => 'INTEGER'],
                            'properties' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'groupName' => ['type' => 'STRING'],
                                        'options' => [
                                            'type' => 'ARRAY',
                                            'items' => ['type' => 'STRING']
                                        ]
                                    ],
                                    'required' => ['groupName', 'options']
                                ]
                            ],
                            'variants' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'options' => [
                                            'type' => 'ARRAY',
                                            'items' => [
                                                'type' => 'OBJECT',
                                                'properties' => [
                                                    'groupName' => ['type' => 'STRING'],
                                                    'optionName' => ['type' => 'STRING']
                                                ],
                                                'required' => ['groupName', 'optionName']
                                            ]
                                        ],
                                        'price' => ['type' => 'NUMBER'],
                                        'stock' => ['type' => 'INTEGER']
                                    ],
                                    'required' => ['options', 'price', 'stock']
                                ]
                            ]
                        ],
                        'required' => ['translations', 'price', 'stock', 'properties', 'variants']
                    ]
                ]
            ],
            'required' => ['products']
        ];

        // Loop through each category and generate products in batches
        $productIndex = 1;
        $localesList = implode(', ', array_values($activeLanguages));

        foreach ($categories as $idx => $category) {
            $isLast = ($idx === $categoriesCount - 1);
            $targetProductCount = $isLast ? $lastCategoryProducts : $productsPerCategory;

            $remaining = $targetProductCount;
            while ($remaining > 0) {
                $chunkSize = min($remaining, 15);
                $remaining -= $chunkSize;

                $productPrompt = sprintf(
                    "We are generating products for the category: '%s' (Description: '%s').
                    Generate exactly %d products for this category.
                    Make sure products are highly relevant to this category.
                    For each product:
                    - Provide name and description translations for the following locales: %s.
                    - Base Price (float, realistic for this item type, e.g. between 10.0 and 200.0), and Stock (integer, e.g. between 10 and 100).
                    - Define a 'properties' object containing 1-2 property groups (like 'Color' or 'Size') and their available options (e.g., Color => ['Red', 'Blue']).
                    - Define a 'variants' array of 2-3 variants. Each variant should specify its 'options' mapping (e.g. Size => 'M', Color => 'Red'), a price (realistic variant price), and stock.
                    Ensure that product names, descriptions, and properties match realistically.",
                    $category['name'],
                    $category['description'] ?? '',
                    $chunkSize,
                    $localesList
                );

                $jsonText = $this->geminiClient->generateText($productPrompt, $productSchema);
                $prodData = json_decode($jsonText, true);

                if (!isset($prodData['products']) || !is_array($prodData['products'])) {
                    $this->logger->warning('Gemini returned an invalid products response for category: ' . $category['name']);
                    continue;
                }

                foreach ($prodData['products'] as $itemData) {
                    $this->importSingleProduct(
                        $itemData,
                        $category['id'],
                        $generateImages,
                        $mediaFolderId,
                        $visibilities,
                        $taxId,
                        $taxRateValue,
                        $productIndex,
                        $activeLanguages,
                        $defaultLangId,
                        $context
                    );
                    $productIndex++;
                }
            }
        }
    }

    private function importSingleProduct(
        array $prodData,
        string $categoryId,
        bool $generateImages,
        ?string $mediaFolderId,
        array $visibilities,
        string $taxId,
        float $taxRateValue,
        int $productIndex,
        array $activeLanguages,
        ?string $defaultLangId,
        Context $context
    ): void {
        // Parse properties and create property groups/options
        $variantOptionsMap = []; // GroupName -> [OptionName -> OptionId]
        if (!empty($prodData['properties']) && is_array($prodData['properties'])) {
            foreach ($prodData['properties'] as $propGroup) {
                $groupName = $propGroup['groupName'] ?? null;
                $optionsList = $propGroup['options'] ?? null;
                if (!$groupName || !is_array($optionsList)) {
                    continue;
                }
                $groupId = $this->getOrCreatePropertyGroup($groupName, $context);
                $variantOptionsMap[$groupName] = [];
                foreach ($optionsList as $optionName) {
                    $optionId = $this->getOrCreatePropertyOption($groupId, (string) $optionName, $context);
                    $variantOptionsMap[$groupName][(string) $optionName] = $optionId;
                }
            }
        }

        $translationsPayload = [];
        foreach ($activeLanguages as $langId => $localeCode) {
            if (isset($prodData['translations'][$localeCode])) {
                $translationsPayload[$langId] = [
                    'name' => $prodData['translations'][$localeCode]['name'],
                    'description' => $prodData['translations'][$localeCode]['description'],
                ];
            }
        }

        $defaultName = '';
        $defaultDesc = '';
        if ($defaultLangId && isset($translationsPayload[$defaultLangId])) {
            $defaultName = $translationsPayload[$defaultLangId]['name'];
            $defaultDesc = $translationsPayload[$defaultLangId]['description'];
        } else {
            $firstTrans = reset($translationsPayload);
            if ($firstTrans) {
                $defaultName = $firstTrans['name'];
                $defaultDesc = $firstTrans['description'];
            }
        }

        // Create parent product
        $productId = Uuid::randomHex();
        $productNumber = 'TDG-' . str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT) . '-' . str_pad((string) $productIndex, 3, '0', STR_PAD_LEFT);
        $grossPrice = (float) ($prodData['price'] ?? 49.99);
        $netPrice = $grossPrice / (1 + ($taxRateValue / 100));

        $productPayload = [
            'id' => $productId,
            'translations' => $translationsPayload,
            'productNumber' => $productNumber,
            'stock' => (int) ($prodData['stock'] ?? 10),
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => $grossPrice,
                'net' => $netPrice,
                'linked' => true,
            ]],
            'taxId' => $taxId,
            'categories' => [['id' => $categoryId]],
            'visibilities' => $visibilities,
            'active' => true,
        ];

        if (!empty($defaultName)) {
            $productPayload['name'] = $defaultName;
            $productPayload['description'] = $defaultDesc;
        }

        // Configure variants options on parent product
        $configuratorSettings = [];
        if (!empty($variantOptionsMap)) {
            foreach ($variantOptionsMap as $groupName => $options) {
                foreach ($options as $optionName => $optionId) {
                    $configuratorSettings[] = [
                        'optionId' => $optionId,
                    ];
                }
            }
            $productPayload['configuratorSettings'] = $configuratorSettings;
        }

        // Add properties to parent product
        if (!empty($configuratorSettings)) {
            $properties = [];
            foreach ($configuratorSettings as $setting) {
                $properties[] = ['id' => $setting['optionId']];
            }
            $productPayload['properties'] = $properties;
        }

        // Handle Cover Image
        $mediaId = $this->handleProductImage($defaultName ?: 'Product', $generateImages, $mediaFolderId, $context);
        if ($mediaId) {
            $productMediaId = Uuid::randomHex();
            $productPayload['coverId'] = $productMediaId;
            $productPayload['media'] = [
                [
                    'id' => $productMediaId,
                    'mediaId' => $mediaId,
                    'position' => 1,
                ]
            ];
        }

        $this->productRepository->create([$productPayload], $context);

        // Create Variant Products
        if (!empty($prodData['variants']) && is_array($prodData['variants']) && !empty($variantOptionsMap)) {
            foreach ($prodData['variants'] as $variantData) {
                if (empty($variantData['options']) || !is_array($variantData['options'])) {
                    continue;
                }

                $variantOptionIds = [];
                $optionSuffix = [];
                $isValidVariant = true;

                foreach ($variantData['options'] as $optionMapping) {
                    $groupName = $optionMapping['groupName'] ?? null;
                    $optionName = $optionMapping['optionName'] ?? null;
                    if ($groupName && $optionName && isset($variantOptionsMap[$groupName][(string) $optionName])) {
                        $variantOptionIds[] = ['id' => $variantOptionsMap[$groupName][(string) $optionName]];
                        $optionSuffix[] = (string) $optionName;
                    } else {
                        $isValidVariant = false;
                        break;
                    }
                }

                if (!$isValidVariant || empty($variantOptionIds)) {
                    continue;
                }

                $variantProductId = Uuid::randomHex();
                $variantProductNumber = $productNumber . '-' . implode('-', $optionSuffix);

                $variantGrossPrice = isset($variantData['price']) ? (float) $variantData['price'] : $grossPrice;
                $variantNetPrice = $variantGrossPrice / (1 + ($taxRateValue / 100));

                $childPayload = [
                    'id' => $variantProductId,
                    'parentId' => $productId,
                    'productNumber' => $variantProductNumber,
                    'stock' => isset($variantData['stock']) ? (int) $variantData['stock'] : (int) ($prodData['stock'] ?? 10),
                    'price' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => $variantGrossPrice,
                        'net' => $variantNetPrice,
                        'linked' => true,
                    ]],
                    'options' => $variantOptionIds,
                    'active' => true,
                ];

                $this->productRepository->create([$childPayload], $context);
            }
        }
    }

    private function resolveSalesChannelLanguages(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('languages.locale');
        $criteria->setLimit(1);
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if (!$salesChannel) {
            return [];
        }

        $languages = [];
        $languagesCollection = $salesChannel->getLanguages();
        if ($languagesCollection) {
            foreach ($languagesCollection as $language) {
                $locale = $language->getLocale();
                if ($locale) {
                    $languages[$language->getId()] = $locale->getCode();
                }
            }
        }

        if (empty($languages)) {
            $languages[Defaults::LANGUAGE_SYSTEM] = 'en-GB';
        }

        return $languages;
    }

    private function getSourceTranslation($entity, array $activeLanguages): ?array
    {
        $translations = $entity->getTranslations();
        if ($translations) {
            foreach ($translations as $translation) {
                if (!empty($translation->getName())) {
                    $langId = $translation->getLanguageId();
                    $locale = $activeLanguages[$langId] ?? 'en-GB';
                    return [
                        'locale' => $locale,
                        'name' => $translation->getName(),
                        'description' => $translation->getDescription() ?? '',
                    ];
                }
            }
        }

        if (method_exists($entity, 'getName') && !empty($entity->getName())) {
            return [
                'locale' => 'en-GB',
                'name' => $entity->getName(),
                'description' => $entity->getDescription() ?? '',
            ];
        }

        return null;
    }

    private function generateMissingTranslations(array $activeLanguages, Context $context): void
    {
        // 1. Process Categories
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('active', true));
        $categories = $this->categoryRepository->search($criteria, $context)->getEntities();

        $categoriesToTranslate = [];
        foreach ($categories as $category) {
            if ($category->getParentId() === null) {
                continue;
            }

            $existingLangIds = [];
            $translations = $category->getTranslations();
            if ($translations) {
                foreach ($translations as $translation) {
                    if (!empty($translation->getName())) {
                        $existingLangIds[] = $translation->getLanguageId();
                    }
                }
            }

            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                if (!in_array($langId, $existingLangIds, true)) {
                    $missingLocales[$langId] = $localeCode;
                }
            }

            if (!empty($missingLocales)) {
                $source = $this->getSourceTranslation($category, $activeLanguages);
                if ($source) {
                    $categoriesToTranslate[] = [
                        'id' => $category->getId(),
                        'sourceLocale' => $source['locale'],
                        'sourceName' => $source['name'],
                        'sourceDescription' => $source['description'],
                        'targetLocales' => array_values($missingLocales),
                        'targetLanguages' => $missingLocales
                    ];
                }
            }
        }

        if (!empty($categoriesToTranslate)) {
            $chunks = array_chunk($categoriesToTranslate, 15);
            foreach ($chunks as $chunk) {
                $this->translateItemsChunk($chunk, $activeLanguages, $this->categoryRepository, $context);
            }
        }

        // 2. Process Products
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('active', true));
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $productsToTranslate = [];
        foreach ($products as $product) {
            $existingLangIds = [];
            $translations = $product->getTranslations();
            if ($translations) {
                foreach ($translations as $translation) {
                    if (!empty($translation->getName())) {
                        $existingLangIds[] = $translation->getLanguageId();
                    }
                }
            }

            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                if (!in_array($langId, $existingLangIds, true)) {
                    $missingLocales[$langId] = $localeCode;
                }
            }

            if (!empty($missingLocales)) {
                $source = $this->getSourceTranslation($product, $activeLanguages);
                if ($source) {
                    $productsToTranslate[] = [
                        'id' => $product->getId(),
                        'sourceLocale' => $source['locale'],
                        'sourceName' => $source['name'],
                        'sourceDescription' => $source['description'],
                        'targetLocales' => array_values($missingLocales),
                        'targetLanguages' => $missingLocales
                    ];
                }
            }
        }

        if (!empty($productsToTranslate)) {
            $chunks = array_chunk($productsToTranslate, 15);
            foreach ($chunks as $chunk) {
                $this->translateItemsChunk($chunk, $activeLanguages, $this->productRepository, $context);
            }
        }
    }

    private function translateItemsChunk(array $chunk, array $activeLanguages, EntityRepository $repository, Context $context): void
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'id' => ['type' => 'STRING'],
                            'translations' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'locale' => ['type' => 'STRING'],
                                        'name' => ['type' => 'STRING'],
                                        'description' => ['type' => 'STRING']
                                    ],
                                    'required' => ['locale', 'name', 'description']
                                ]
                            ]
                        ],
                        'required' => ['id', 'translations']
                    ]
                ]
            ],
            'required' => ['items']
        ];

        $prompt = "You are a professional translator. Translate the following items into their missing target locales.
For each item, we provide its ID, the source name and description (with its locale), and the target locales you must translate it into.
Provide the translated name and description for each target locale requested.

Items:
";

        foreach ($chunk as $item) {
            $prompt .= sprintf(
                "Item ID: %s\nSource Locale: %s\nSource Name: %s\nSource Description: %s\nTranslate to Locales: %s\n---\n",
                $item['id'],
                $item['sourceLocale'],
                $item['sourceName'],
                $item['sourceDescription'],
                implode(', ', $item['targetLocales'])
            );
        }

        try {
            $jsonText = $this->geminiClient->generateText($prompt, $schema);
            $resData = json_decode($jsonText, true);

            if (!isset($resData['items']) || !is_array($resData['items'])) {
                $this->logger->warning('Gemini returned an invalid translation response format.');
                return;
            }

            $updatePayloads = [];
            foreach ($resData['items'] as $responseItem) {
                $id = $responseItem['id'];

                $matchingItem = null;
                foreach ($chunk as $cItem) {
                    if ($cItem['id'] === $id) {
                        $matchingItem = $cItem;
                        break;
                    }
                }

                if (!$matchingItem) {
                    continue;
                }

                $translationsPayload = [];
                foreach ($responseItem['translations'] as $trans) {
                    $locale = $trans['locale'];

                    $langId = array_search($locale, $matchingItem['targetLanguages'], true);
                    if ($langId) {
                        $translationsPayload[$langId] = [
                            'name' => $trans['name'],
                            'description' => $trans['description']
                        ];
                    }
                }

                if (!empty($translationsPayload)) {
                    $updatePayloads[] = [
                        'id' => $id,
                        'translations' => $translationsPayload
                    ];
                }
            }

            if (!empty($updatePayloads)) {
                $repository->update($updatePayloads, $context);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to translate items chunk: ' . $e->getMessage());
            throw $e;
        }
    }


    private function resolveNavigationRootCategoryId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        
        return $salesChannel ? $salesChannel->getNavigationCategoryId() : null;
    }

    private function resolveProductVisibilities(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $salesChannels = $this->salesChannelRepository->search($criteria, $context);
        
        $visibilities = [];
        foreach ($salesChannels as $salesChannel) {
            $visibilities[] = [
                'salesChannelId' => $salesChannel->getId(),
                'visibility' => 30, // ProductVisibilityDefinition::VISIBILITY_ALL
            ];
        }

        return $visibilities;
    }

    private function resolveTax(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $tax = $this->taxRepository->search($criteria, $context)->first();

        if ($tax) {
            return [
                'id' => $tax->getId(),
                'rate' => (float) $tax->getTaxRate(),
            ];
        }

        // Create fallback tax rate
        $taxId = Uuid::randomHex();
        $this->taxRepository->create([[
            'id' => $taxId,
            'name' => 'Standard Tax',
            'taxRate' => 19.0,
        ]], $context);

        return [
            'id' => $taxId,
            'rate' => 19.0,
        ];
    }

    private function resolveProductMediaFolderId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('defaultFolder.entity', 'product'));
        $criteria->setLimit(1);
        $folder = $this->mediaFolderRepository->search($criteria, $context)->first();

        return $folder ? $folder->getId() : null;
    }

    private function getOrCreatePropertyGroup(string $name, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $group = $this->propertyGroupRepository->search($criteria, $context)->first();

        if ($group) {
            return $group->getId();
        }

        $id = Uuid::randomHex();
        $this->propertyGroupRepository->create([[
            'id' => $id,
            'name' => $name,
            'displayType' => 'text',
            'sortingType' => 'alphanumeric',
        ]], $context);

        return $id;
    }

    private function getOrCreatePropertyOption(string $groupId, string $name, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addFilter(new EqualsFilter('name', $name));
        $option = $this->propertyGroupOptionRepository->search($criteria, $context)->first();

        if ($option) {
            return $option->getId();
        }

        $id = Uuid::randomHex();
        $this->propertyGroupOptionRepository->create([[
            'id' => $id,
            'groupId' => $groupId,
            'name' => $name,
        ]], $context);

        return $id;
    }

    private function handleProductImage(string $productName, bool $generateImages, ?string $mediaFolderId, Context $context): ?string
    {
        $binaryData = null;
        $extension = 'jpg';
        $mimeType = 'image/jpeg';

        if ($generateImages) {
            try {
                $prompt = sprintf(
                    'A clean professional studio product photograph of %s, isolated on a white background, e-commerce catalog style.',
                    $productName
                );
                $binaryData = $this->geminiClient->generateImage($prompt);
            } catch (\Throwable $e) {
                // Log the exception
                $this->logger->error('Gemini Image Generation failed: ' . $e->getMessage(), [
                    'product' => $productName,
                    'exception' => $e,
                ]);
                // Fallback to local GD generation on error
                $binaryData = $this->generatePlaceholderImage($productName);
            }
        } else {
            return null; // The user chose not to generate images
        }

        if (!$binaryData) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'tdg_img');
        file_put_contents($tempFile, $binaryData);

        try {
            $mediaFile = new MediaFile(
                $tempFile,
                $mimeType,
                $extension,
                filesize($tempFile)
            );

            $mediaId = Uuid::randomHex();
            $mediaData = [
                'id' => $mediaId,
            ];
            if ($mediaFolderId) {
                $mediaData['mediaFolderId'] = $mediaFolderId;
            }

            $this->mediaRepository->create([$mediaData], $context);

            $fileName = preg_replace('/[^a-z0-9]+/', '-', strtolower($productName)) . '-' . Uuid::randomHex();

            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                $fileName,
                $mediaId,
                $context
            );

            return $mediaId;
        } catch (\Throwable $e) {
            return null;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function generatePlaceholderImage(string $name): string
    {
        $im = imagecreatetruecolor(600, 600);
        if (!$im) {
            throw new \Exception('Failed to initialize GD image.');
        }

        // Modern pastel color palette
        $colors = [
            [240, 128, 128], // Light Coral
            [255, 160, 122], // Light Salmon
            [144, 238, 144], // Light Green
            [173, 216, 230], // Light Blue
            [221, 160, 221], // Plum
            [250, 250, 210], // Light Goldenrod Yellow
            [255, 192, 203], // Pink
            [224, 255, 255], // Light Cyan
        ];

        // Select color based on name hash
        $colorIndex = abs(crc32($name)) % count($colors);
        $rgb = $colors[$colorIndex];

        $bgColor = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($im, 0, 0, $bgColor);

        // Draw center white circle
        $white = imagecolorallocate($im, 255, 255, 255);
        $textCol = imagecolorallocate($im, 80, 80, 80);

        imagefilledellipse($im, 300, 300, 400, 400, $white);

        // Write initials in the center
        $initials = strtoupper(substr($name, 0, 2));
        $font = 5;
        $charWidth = imagefontwidth($font);
        $charHeight = imagefontheight($font);
        $textWidth = strlen($initials) * $charWidth;
        
        imagestring($im, $font, 300 - (int)($textWidth / 2), 290 - (int)($charHeight / 2), $initials, $textCol);

        // Write product name below
        $nameFont = 3;
        $nameWidth = strlen($name) * imagefontwidth($nameFont);
        if ($nameWidth < 360) {
            imagestring($im, $nameFont, 300 - (int)($nameWidth / 2), 330, $name, $textCol);
        } else {
            $truncatedName = substr($name, 0, 25) . '...';
            $truncWidth = strlen($truncatedName) * imagefontwidth($nameFont);
            imagestring($im, $nameFont, 300 - (int)($truncWidth / 2), 330, $truncatedName, $textCol);
        }

        ob_start();
        imagejpeg($im, null, 90);
        $data = ob_get_clean();

        imagedestroy($im);

        return $data;
    }
}
