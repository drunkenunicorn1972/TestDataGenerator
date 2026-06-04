import './page/test-data-generator-index';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Locale.register('en-GB', enGB);
Shopware.Locale.register('de-DE', deDE);

Shopware.Module.register('test-data-generator', {
    type: 'plugin',
    name: 'test-data-generator',
    title: 'test-data-generator.general.mainMenuItemGeneral',
    description: 'test-data-generator.general.descriptionText',
    color: '#3f9ce8',
    icon: 'regular-database',
    routePrefixName: 'test-data-generator',
    routePrefixPath: 'test-data-generator',

    routes: {
        index: {
            component: 'test-data-generator-index',
            path: 'index',
            meta: {
                parentPath: 'sw.product.index',
            },
        },
    },

    navigation: [{
        id: 'test-data-generator',
        label: 'test-data-generator.general.mainMenuItemGeneral',
        color: '#3f9ce8',
        icon: 'regular-database',
        path: 'test-data-generator.index',
        parent: 'sw-catalogue',
        position: 100,
    }],

    settingsItem: {
        group: 'plugins',
        to: 'test-data-generator.index',
        icon: 'regular-database',
        label: 'test-data-generator.general.mainMenuItemGeneral',
    },
});
