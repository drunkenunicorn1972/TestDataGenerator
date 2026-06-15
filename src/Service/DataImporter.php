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
    private array $propertyGroupCache = [];
    private array $propertyOptionCache = [];

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

    public function importData(
        int $categoriesCount,
        int $productsCount,
        bool $generateImages,
        bool $useExistingCategories,
        bool $createTranslationsOnly,
        Context $context,
        ?string $selectedCategoryId = null,
        bool $deleteTestDataBeforeGeneration = false
    ): void {
        if ($deleteTestDataBeforeGeneration) {
            $this->deleteTestData($context);
        }
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

            $hasChildCategories = false;
            if ($selectedCategoryId) {
                foreach ($allActiveCategories as $category) {
                    if ($category->getParentId() === $selectedCategoryId) {
                        $hasChildCategories = true;
                        break;
                    }
                    $path = $category->getPath() ?? '';
                    $parentIds = array_filter(explode('|', $path));
                    if (in_array($selectedCategoryId, $parentIds, true)) {
                        $hasChildCategories = true;
                        break;
                    }
                }
            }

            foreach ($allActiveCategories as $category) {
                // Exclude root navigation category and any category without parent (system categories)
                if ($category->getId() === $rootCategoryId || $category->getParentId() === null) {
                    continue;
                }

                // Filter by selected category
                if ($selectedCategoryId) {
                    if ($hasChildCategories) {
                        $path = $category->getPath() ?? '';
                        $parentIds = array_filter(explode('|', $path));
                        if (!in_array($selectedCategoryId, $parentIds, true) && $category->getParentId() !== $selectedCategoryId) {
                            continue;
                        }
                    } else {
                        if ($category->getId() !== $selectedCategoryId) {
                            continue;
                        }
                    }
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
            // Generate categories structure using Gemini with translations and SEO data
            $translationsProperties = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translationsProperties[$localeCode] = [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'metaTitle' => ['type' => 'STRING'],
                        'metaDescription' => ['type' => 'STRING']
                    ],
                    'required' => ['name', 'description', 'metaTitle', 'metaDescription']
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
                For each category, you must provide the name, description, SEO meta title, and SEO meta description translated into all of the following locales: %s.
                Ensure translations are high quality, natural, and accurately reflect the same category in each language. The meta title and meta description should be optimized for SEO in each respective language.",
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
                            'metaTitle' => $catEntry['translations'][$localeCode]['metaTitle'],
                            'metaDescription' => $catEntry['translations'][$localeCode]['metaDescription'],
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
                    $categoryPayload['metaTitle'] = $translationsPayload[$defaultLangId]['metaTitle'];
                    $categoryPayload['metaDescription'] = $translationsPayload[$defaultLangId]['metaDescription'];
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

        // Define schema for single-category products generation with translations and SEO data
        $translationsProperties = [];
        $translationsPropertiesProperties = [];
        foreach ($activeLanguages as $langId => $localeCode) {
            $translationsProperties[$localeCode] = [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING'],
                    'metaTitle' => ['type' => 'STRING'],
                    'metaDescription' => ['type' => 'STRING']
                ],
                'required' => ['name', 'description', 'metaTitle', 'metaDescription']
            ];
            $translationsPropertiesProperties[$localeCode] = [
                'type' => 'STRING'
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
                                        'id' => ['type' => 'STRING'],
                                        'translations' => [
                                            'type' => 'OBJECT',
                                            'properties' => $translationsPropertiesProperties,
                                            'required' => array_values($activeLanguages)
                                        ],
                                        'options' => [
                                            'type' => 'ARRAY',
                                            'items' => [
                                                'type' => 'OBJECT',
                                                'properties' => [
                                                    'id' => ['type' => 'STRING'],
                                                    'translations' => [
                                                        'type' => 'OBJECT',
                                                        'properties' => $translationsPropertiesProperties,
                                                        'required' => array_values($activeLanguages)
                                                    ]
                                                ],
                                                'required' => ['id', 'translations']
                                            ]
                                        ]
                                    ],
                                    'required' => ['id', 'translations', 'options']
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
                                                    'groupId' => ['type' => 'STRING'],
                                                    'optionId' => ['type' => 'STRING']
                                                ],
                                                'required' => ['groupId', 'optionId']
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
                    - Provide name, description (MUST be a detailed, high-quality description containing 2-3 paragraphs, formatted with HTML paragraph tags <p>...</p>), SEO meta title, and SEO meta description translations for the following locales under the 'translations' object: %s.
                    - Base Price (float, realistic for this item type, e.g. between 10.0 and 200.0), and Stock (integer, e.g. between 10 and 100).
                    - Define a 'properties' array containing 1-2 property groups and their available options.
                      For both property groups and options, you must provide translations for each of the active locales: %s.
                      Example property group: id => \"color\", translations => {\"en-GB\": \"Color\", \"de-DE\": \"Farbe\"}, options => [ { id => \"red\", translations => {\"en-GB\": \"Red\", \"de-DE\": \"Rot\"} } ]
                    - GUIDELINES FOR PROPERTY GROUPS:
                      * Only use 'Size' (or 'Größe' in German) as a property group name for standard apparel/clothing sizes (e.g. S, M, L, XL, XXL).
                      * Do NOT use the generic name 'Size' (or 'Größe') for physical dimensions, lengths, capacities, or measurements (e.g. '1 meter', '2 meter', '500ml', '15.6 inch'). Instead, use a clear, descriptive property group name that exactly represents the dimension (e.g. 'Length', 'Cable Length', 'Volume', 'Screen Size', 'Height').
                      * Ensure group names and option names are consistently capitalized (use proper Title Case, e.g. 'Size' instead of 'size', 'Length' instead of 'length').
                    - Define a 'variants' array of 2-3 variants. Each variant should specify its 'options' mapping using the respective groupId and optionId strings defined in the properties array, a price, and stock.
                    Ensure that product names, descriptions, and properties match realistically and translations are high quality, natural, and accurately reflect the same information in each language.",
                    $category['name'],
                    $category['description'] ?? '',
                    $chunkSize,
                    $localesList,
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
        $variantOptionsMap = []; // GroupId -> [OptionId -> OptionDbId]
        if (!empty($prodData['properties']) && is_array($prodData['properties'])) {
            foreach ($prodData['properties'] as $propGroup) {
                $groupIdentifier = $propGroup['id'] ?? null;
                $groupTranslations = $propGroup['translations'] ?? null;
                $optionsList = $propGroup['options'] ?? null;
                if (!$groupIdentifier || !is_array($groupTranslations) || !is_array($optionsList)) {
                    continue;
                }

                // Convert locale-coded keys to language ID keys for our db query/create
                $groupTransPayload = [];
                foreach ($activeLanguages as $langId => $localeCode) {
                    if (isset($groupTranslations[$localeCode])) {
                        $groupTransPayload[$langId] = [
                            'name' => $groupTranslations[$localeCode]
                        ];
                    }
                }

                $groupId = $this->getOrCreatePropertyGroup($groupTransPayload, $defaultLangId, $context);
                $variantOptionsMap[$groupIdentifier] = [];

                foreach ($optionsList as $optionEntry) {
                    $optionIdentifier = $optionEntry['id'] ?? null;
                    $optionTranslations = $optionEntry['translations'] ?? null;
                    if (!$optionIdentifier || !is_array($optionTranslations)) {
                        continue;
                    }

                    $optionTransPayload = [];
                    foreach ($activeLanguages as $langId => $localeCode) {
                        if (isset($optionTranslations[$localeCode])) {
                            $optionTransPayload[$langId] = [
                                'name' => $optionTranslations[$localeCode]
                            ];
                        }
                    }

                    $optionId = $this->getOrCreatePropertyOption($groupId, $optionTransPayload, $defaultLangId, $context);
                    $variantOptionsMap[$groupIdentifier][$optionIdentifier] = $optionId;
                }
            }
        }

        $translationsPayload = [];
        foreach ($activeLanguages as $langId => $localeCode) {
            if (isset($prodData['translations'][$localeCode])) {
                $translationsPayload[$langId] = [
                    'name' => $prodData['translations'][$localeCode]['name'],
                    'description' => $prodData['translations'][$localeCode]['description'],
                    'metaTitle' => $prodData['translations'][$localeCode]['metaTitle'],
                    'metaDescription' => $prodData['translations'][$localeCode]['metaDescription'],
                ];
            }
        }

        $defaultName = '';
        $defaultDesc = '';
        $defaultMetaTitle = '';
        $defaultMetaDesc = '';
        if ($defaultLangId && isset($translationsPayload[$defaultLangId])) {
            $defaultName = $translationsPayload[$defaultLangId]['name'];
            $defaultDesc = $translationsPayload[$defaultLangId]['description'];
            $defaultMetaTitle = $translationsPayload[$defaultLangId]['metaTitle'];
            $defaultMetaDesc = $translationsPayload[$defaultLangId]['metaDescription'];
        } else {
            $firstTrans = reset($translationsPayload);
            if ($firstTrans) {
                $defaultName = $firstTrans['name'];
                $defaultDesc = $firstTrans['description'];
                $defaultMetaTitle = $firstTrans['metaTitle'];
                $defaultMetaDesc = $firstTrans['metaDescription'];
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
            $productPayload['metaTitle'] = $defaultMetaTitle;
            $productPayload['metaDescription'] = $defaultMetaDesc;
        }

        // Configure variants options on parent product
        $configuratorSettings = [];
        if (!empty($variantOptionsMap)) {
            foreach ($variantOptionsMap as $groupIdentifier => $options) {
                foreach ($options as $optionIdentifier => $optionId) {
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
                    $groupIdentifier = $optionMapping['groupId'] ?? null;
                    $optionIdentifier = $optionMapping['optionId'] ?? null;
                    if ($groupIdentifier && $optionIdentifier && isset($variantOptionsMap[$groupIdentifier][$optionIdentifier])) {
                        $variantOptionIds[] = ['id' => $variantOptionsMap[$groupIdentifier][$optionIdentifier]];
                        $optionSuffix[] = $optionIdentifier;
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
                        'description' => method_exists($translation, 'getDescription') ? ($translation->getDescription() ?? '') : '',
                        'metaTitle' => method_exists($translation, 'getMetaTitle') ? ($translation->getMetaTitle() ?? '') : '',
                        'metaDescription' => method_exists($translation, 'getMetaDescription') ? ($translation->getMetaDescription() ?? '') : '',
                    ];
                }
            }
        }

        if (method_exists($entity, 'getName') && !empty($entity->getName())) {
            return [
                'locale' => 'en-GB',
                'name' => $entity->getName(),
                'description' => method_exists($entity, 'getDescription') ? ($entity->getDescription() ?? '') : '',
                'metaTitle' => method_exists($entity, 'getMetaTitle') ? ($entity->getMetaTitle() ?? '') : '',
                'metaDescription' => method_exists($entity, 'getMetaDescription') ? ($entity->getMetaDescription() ?? '') : '',
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

            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translation = null;
                $translations = $category->getTranslations();
                if ($translations) {
                    foreach ($translations as $t) {
                        if ($t->getLanguageId() === $langId) {
                            $translation = $t;
                            break;
                        }
                    }
                }

                $isMissing = !$translation 
                    || empty($translation->getName()) 
                    || empty($translation->getDescription()) 
                    || empty($translation->getMetaTitle()) 
                    || empty($translation->getMetaDescription());

                if ($isMissing) {
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
                        'sourceMetaTitle' => $source['metaTitle'],
                        'sourceMetaDescription' => $source['metaDescription'],
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
            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translation = null;
                $translations = $product->getTranslations();
                if ($translations) {
                    foreach ($translations as $t) {
                        if ($t->getLanguageId() === $langId) {
                            $translation = $t;
                            break;
                        }
                    }
                }

                $isMissing = !$translation 
                    || empty($translation->getName()) 
                    || empty($translation->getDescription()) 
                    || empty($translation->getMetaTitle()) 
                    || empty($translation->getMetaDescription());

                if ($isMissing) {
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
                        'sourceMetaTitle' => $source['metaTitle'],
                        'sourceMetaDescription' => $source['metaDescription'],
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

        // 3. Process Property Groups
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $propertyGroups = $this->propertyGroupRepository->search($criteria, $context)->getEntities();

        $groupsToTranslate = [];
        foreach ($propertyGroups as $group) {
            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translation = null;
                $translations = $group->getTranslations();
                if ($translations) {
                    foreach ($translations as $t) {
                        if ($t->getLanguageId() === $langId) {
                            $translation = $t;
                            break;
                        }
                    }
                }

                $isMissing = !$translation || empty($translation->getName());
                if ($isMissing) {
                    $missingLocales[$langId] = $localeCode;
                }
            }

            if (!empty($missingLocales)) {
                $source = $this->getSourceTranslation($group, $activeLanguages);
                if ($source) {
                    $groupsToTranslate[] = [
                        'id' => $group->getId(),
                        'sourceLocale' => $source['locale'],
                        'sourceName' => $source['name'],
                        'sourceDescription' => '',
                        'sourceMetaTitle' => '',
                        'sourceMetaDescription' => '',
                        'targetLocales' => array_values($missingLocales),
                        'targetLanguages' => $missingLocales
                    ];
                }
            }
        }

        if (!empty($groupsToTranslate)) {
            $chunks = array_chunk($groupsToTranslate, 15);
            foreach ($chunks as $chunk) {
                $this->translateItemsChunk($chunk, $activeLanguages, $this->propertyGroupRepository, $context);
            }
        }

        // 4. Process Property Options
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $propertyOptions = $this->propertyGroupOptionRepository->search($criteria, $context)->getEntities();

        $optionsToTranslate = [];
        foreach ($propertyOptions as $option) {
            $missingLocales = [];
            foreach ($activeLanguages as $langId => $localeCode) {
                $translation = null;
                $translations = $option->getTranslations();
                if ($translations) {
                    foreach ($translations as $t) {
                        if ($t->getLanguageId() === $langId) {
                            $translation = $t;
                            break;
                        }
                    }
                }

                $isMissing = !$translation || empty($translation->getName());
                if ($isMissing) {
                    $missingLocales[$langId] = $localeCode;
                }
            }

            if (!empty($missingLocales)) {
                $source = $this->getSourceTranslation($option, $activeLanguages);
                if ($source) {
                    $optionsToTranslate[] = [
                        'id' => $option->getId(),
                        'sourceLocale' => $source['locale'],
                        'sourceName' => $source['name'],
                        'sourceDescription' => '',
                        'sourceMetaTitle' => '',
                        'sourceMetaDescription' => '',
                        'targetLocales' => array_values($missingLocales),
                        'targetLanguages' => $missingLocales
                    ];
                }
            }
        }

        if (!empty($optionsToTranslate)) {
            $chunks = array_chunk($optionsToTranslate, 15);
            foreach ($chunks as $chunk) {
                $this->translateItemsChunk($chunk, $activeLanguages, $this->propertyGroupOptionRepository, $context);
            }
        }
    }

    private function translateItemsChunk(array $chunk, array $activeLanguages, EntityRepository $repository, Context $context): void
    {
        $hasDescription = ($repository === $this->categoryRepository || $repository === $this->productRepository);

        $transProperties = [
            'locale' => ['type' => 'STRING'],
            'name' => ['type' => 'STRING'],
        ];
        $transRequired = ['locale', 'name'];

        if ($hasDescription) {
            $transProperties['description'] = ['type' => 'STRING'];
            $transProperties['metaTitle'] = ['type' => 'STRING'];
            $transProperties['metaDescription'] = ['type' => 'STRING'];
            
            $transRequired[] = 'description';
            $transRequired[] = 'metaTitle';
            $transRequired[] = 'metaDescription';
        }

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
                                    'properties' => $transProperties,
                                    'required' => $transRequired
                                ]
                            ]
                        ],
                        'required' => ['id', 'translations']
                    ]
                ]
            ],
            'required' => ['items']
        ];

        if ($hasDescription) {
            $prompt = "You are a professional translator and SEO optimizer.
For each item, we provide its ID, the source name, description, meta title, and meta description (with its source locale), and the target locales.
Translate the name and description into each target locale.
Also, translate or generate/fill high-quality SEO meta titles and meta descriptions for each target locale.
If the source meta title or description is missing, generate appropriate SEO meta title and description based on the name and description in that target language.
Provide the results in the requested JSON structure.

Items:
";
        } else {
            $prompt = "You are a professional translator.
For each item, we provide its ID, the source name (with its source locale), and the target locales you must translate it into.
Provide the translated name for each target locale requested.
Provide the results in the requested JSON structure.

Items:
";
        }

        foreach ($chunk as $item) {
            if ($hasDescription) {
                $prompt .= sprintf(
                    "Item ID: %s\nSource Locale: %s\nSource Name: %s\nSource Description: %s\nSource Meta Title: %s\nSource Meta Description: %s\nTranslate to Locales: %s\n---\n",
                    $item['id'],
                    $item['sourceLocale'],
                    $item['sourceName'],
                    $item['sourceDescription'] ?? '',
                    $item['sourceMetaTitle'] ?? '',
                    $item['sourceMetaDescription'] ?? '',
                    implode(', ', $item['targetLocales'])
                );
            } else {
                $prompt .= sprintf(
                    "Item ID: %s\nSource Locale: %s\nSource Name: %s\nTranslate to Locales: %s\n---\n",
                    $item['id'],
                    $item['sourceLocale'],
                    $item['sourceName'],
                    implode(', ', $item['targetLocales'])
                );
            }
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
                            'name' => $trans['name']
                        ];
                        if ($hasDescription) {
                            $translationsPayload[$langId]['description'] = $trans['description'] ?? '';
                            $translationsPayload[$langId]['metaTitle'] = $trans['metaTitle'] ?? '';
                            $translationsPayload[$langId]['metaDescription'] = $trans['metaDescription'] ?? '';
                        }
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

    private function getOrCreatePropertyGroup(array $translations, ?string $defaultLangId, Context $context): string
    {
        $defaultName = trim($translations[$defaultLangId]['name'] ?? reset($translations)['name']);

        if (isset($this->propertyGroupCache[$defaultName])) {
            return $this->propertyGroupCache[$defaultName];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $defaultName));
        $group = $this->propertyGroupRepository->search($criteria, $context)->first();

        if ($group) {
            $this->propertyGroupCache[$defaultName] = $group->getId();
            return $group->getId();
        }

        $id = Uuid::randomHex();
        
        $translationsPayload = [];
        foreach ($translations as $langId => $trans) {
            $translationsPayload[$langId] = [
                'name' => $trans['name'],
            ];
        }

        $this->propertyGroupRepository->create([[
            'id' => $id,
            'translations' => $translationsPayload,
            'name' => $defaultName,
            'displayType' => 'text',
            'sortingType' => 'alphanumeric',
        ]], $context);

        $this->propertyGroupCache[$defaultName] = $id;

        return $id;
    }

    private function getOrCreatePropertyOption(string $groupId, array $translations, ?string $defaultLangId, Context $context): string
    {
        $defaultName = trim($translations[$defaultLangId]['name'] ?? reset($translations)['name']);
        $cacheKey = $groupId . '_' . $defaultName;

        if (isset($this->propertyOptionCache[$cacheKey])) {
            return $this->propertyOptionCache[$cacheKey];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('groupId', $groupId));
        $criteria->addFilter(new EqualsFilter('name', $defaultName));
        $option = $this->propertyGroupOptionRepository->search($criteria, $context)->first();

        if ($option) {
            $this->propertyOptionCache[$cacheKey] = $option->getId();
            return $option->getId();
        }

        $id = Uuid::randomHex();
        
        $translationsPayload = [];
        foreach ($translations as $langId => $trans) {
            $translationsPayload[$langId] = [
                'name' => $trans['name'],
            ];
        }

        $this->propertyGroupOptionRepository->create([[
            'id' => $id,
            'groupId' => $groupId,
            'translations' => $translationsPayload,
            'name' => $defaultName,
        ]], $context);

        $this->propertyOptionCache[$cacheKey] = $id;

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

    private function deleteTestData(Context $context): void
    {
        $this->propertyGroupCache = [];
        $this->propertyOptionCache = [];

        // 1. Delete all products
        do {
            $criteria = new Criteria();
            $criteria->setLimit(100);
            $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();
            if (!empty($productIds)) {
                $deletePayload = array_map(fn($id) => ['id' => $id], $productIds);
                $this->productRepository->delete($deletePayload, $context);
            }
        } while (!empty($productIds));

        // 2. Delete all property groups
        do {
            $criteria = new Criteria();
            $criteria->setLimit(100);
            $groupIds = $this->propertyGroupRepository->searchIds($criteria, $context)->getIds();
            if (!empty($groupIds)) {
                $deletePayload = array_map(fn($id) => ['id' => $id], $groupIds);
                $this->propertyGroupRepository->delete($deletePayload, $context);
            }
        } while (!empty($groupIds));
    }
}
