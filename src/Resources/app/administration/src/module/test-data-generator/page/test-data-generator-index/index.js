import template from './test-data-generator-index.html.twig';
import './test-data-generator-index.scss';

Shopware.Component.register('test-data-generator-index', {
    template,

    inject: [
        'systemConfigApiService',
        'repositoryFactory'
    ],

    data() {
        return {
            categoriesCount: 5,
            productsCount: 20,
            generateImages: false,
            useExistingCategories: false,
            createTranslationsOnly: false,
            isLoading: false,
            status: '',
            apiKey: '',
            statusTimer: null,
            categoryCollection: null,
            selectedCategoryId: null
        };
    },

    computed: {
        isConfigured() {
            return !!this.apiKey;
        },

        statusText() {
            if (!this.status) {
                return this.$tc('test-data-generator.general.statusIdle');
            }
            if (this.status === 'running') {
                return this.$tc('test-data-generator.general.statusRunning');
            }
            if (this.status === 'success') {
                return this.$tc('test-data-generator.general.statusSuccess');
            }
            if (this.status.startsWith('failed:')) {
                return this.$tc('test-data-generator.general.statusFailed', 0, { error: this.status.replace('failed:', '').trim() });
            }
            return this.status;
        },

        statusClass() {
            if (this.status === 'running') {
                return 'status-running';
            }
            if (this.status === 'success') {
                return 'status-success';
            }
            if (this.status && this.status.startsWith('failed:')) {
                return 'status-failed';
            }
            return 'status-idle';
        }
    },

    created() {
        this.createdComponent();
    },

    beforeUnmount() {
        if (this.statusTimer) {
            clearInterval(this.statusTimer);
        }
    },

    methods: {
        createdComponent() {
            this.categoryCollection = this.getEmptyCategoryCollection();
            this.loadConfig();
            // Poll generation status
            this.statusTimer = setInterval(() => {
                this.pollStatus();
            }, 3000);
        },

        loadConfig() {
            this.isLoading = true;
            this.systemConfigApiService.getValues('TestDataGenerator.config')
                .then((values) => {
                    this.apiKey = values['TestDataGenerator.config.apiKey'] || '';
                    this.status = values['TestDataGenerator.config.status'] || '';
                })
                .catch(() => {})
                .finally(() => {
                    this.isLoading = false;
                });
        },

        pollStatus() {
            this.systemConfigApiService.getValues('TestDataGenerator.config')
                .then((values) => {
                    this.status = values['TestDataGenerator.config.status'] || '';
                })
                .catch(() => {});
        },

        onGenerate() {
            if (!this.isConfigured) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('test-data-generator.general.apiConfigError')
                });
                return;
            }

            this.isLoading = true;
            const httpClient = Shopware.Application.getContainer('init').httpClient;

            httpClient.post('/test-data-generator/generate', {
                categoriesCount: this.categoriesCount,
                productsCount: this.productsCount,
                generateImages: this.generateImages,
                useExistingCategories: this.useExistingCategories,
                createTranslationsOnly: this.createTranslationsOnly,
                selectedCategoryId: this.selectedCategoryId
            })
            .then(() => {
                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc('test-data-generator.general.toastSuccess')
                });
                this.pollStatus();
            })
            .catch((error) => {
                const message = error.response?.data?.message || error.message;
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('test-data-generator.general.toastError', 0, { error: message })
                });
            })
            .finally(() => {
                this.isLoading = false;
            });
        },

        getEmptyCategoryCollection() {
            const categoryRepository = this.repositoryFactory.create('category');
            return new Shopware.Data.EntityCollection(
                categoryRepository.route,
                categoryRepository.entityName,
                Shopware.Context.api
            );
        },

        onCategorySelected(category) {
            this.selectedCategoryId = category.id;
        },

        onCategoryRemoved() {
            this.selectedCategoryId = null;
        }
    },

    mixins: [
        Shopware.Mixin.getByName('notification')
    ]
});
