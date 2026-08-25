import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const declarationSection = (contents, declaration, nextDeclaration) => {
    const start = contents.indexOf(`const ${declaration} =`);
    const end = contents.indexOf(`const ${nextDeclaration} =`, start + 1);

    assert.notEqual(start, -1, `${declaration} must exist`);
    assert.notEqual(end, -1, `${nextDeclaration} must follow ${declaration}`);

    return contents.slice(start, end);
};

const balancedBlockAfter = (contents, marker) => {
    const markerIndex = contents.indexOf(marker);
    assert.notEqual(markerIndex, -1, `${marker} must exist`);

    const openingBrace = contents.indexOf('{', markerIndex);
    assert.notEqual(openingBrace, -1, `${marker} must open a block`);

    let depth = 0;
    for (let index = openingBrace; index < contents.length; index += 1) {
        if (contents[index] === '{') {
            depth += 1;
        } else if (contents[index] === '}') {
            depth -= 1;
            if (depth === 0) {
                return contents.slice(openingBrace + 1, index);
            }
        }
    }

    assert.fail(`${marker} must close its block`);
};

test('workspace breadcrumb choices come from the canonical visible workspace hierarchy', () => {
    const resolver = source('resources/js/utils/workspaceBreadcrumbs.js');
    const workspaceHub = source('resources/js/utils/workspaceHub.js');

    assert.match(resolver, /import \{ buildWorkspaceHubCategories \} from '@\/utils\/workspaceHub';/);
    assert.match(workspaceHub, /export function buildWorkspaceHubCategories\(/);
    assert.match(workspaceHub, /\.map\(\(moduleKey\) => modules\[moduleKey\]\)\s*\.filter\(\(module\) => Boolean\(module\?\.visible\)\);/);
    assert.match(workspaceHub, /modules: categoryModules,\s*visible: categoryModules\.length > 0,/);
    assert.match(resolver, /const visibleCategories = buildWorkspaceHubCategories\(\{\s*account,\s*planningPendingCount,\s*\}\)\.filter\(\(category\) => category\.visible\);/);
    assert.match(resolver, /visibleCategories\.find\(\(category\) => category\.key === pageProps\.category\)/);
    assert.match(resolver, /visibleCategories\.find\(\(category\) => routeMatches\(category\.match \|\| \[\]\)\)/);
    assert.match(resolver, /const currentModule = currentCategory\.modules\.find\(\(module\) =>/);

    assert.match(resolver, /const makeSiblingItems = \(items = \[\], currentKey = null, t = \(value\) => value\) => items\.map\(\(item\) => \(\{/);
    assert.match(resolver, /label: t\(item\.labelKey\),/);
    assert.match(resolver, /href: safeRoute\(item\.routeName, item\.routeParams\),/);
    assert.match(resolver, /current: item\.key === currentKey,/);
    assert.match(resolver, /const categorySiblings = makeSiblingItems\(visibleCategories, currentCategory\.key, t\);/);
    assert.equal((resolver.match(/siblings: categorySiblings,/g) || []).length, 2);
    assert.match(resolver, /siblings: makeSiblingItems\(currentCategory\.modules, currentModule\.key, t\),/);
    assert.match(resolver, /siblingsLabel: t\('workspace_hub\.breadcrumbs\.change_category'\),/);
    assert.match(resolver, /siblingsLabel: t\('workspace_hub\.breadcrumbs\.change_module'\),/);
});

test('workspace breadcrumb navigation has complete French, English and Spanish labels', () => {
    const expected = {
        fr: {
            label: "Fil d'Ariane",
            change_category: 'Changer de catégorie',
            change_module: 'Changer de module',
            change_record: "Changer d'élément : {name}",
            entity_search_placeholder: 'Rechercher un autre élément...',
            entity_loading: 'Recherche en cours...',
            entity_empty: 'Aucun autre élément trouvé.',
            entity_error: 'Impossible de charger les choix.',
            current: 'Actuel',
        },
        en: {
            label: 'Breadcrumb',
            change_category: 'Change category',
            change_module: 'Change module',
            change_record: 'Change item: {name}',
            entity_search_placeholder: 'Search for another item...',
            entity_loading: 'Searching...',
            entity_empty: 'No other item found.',
            entity_error: 'Unable to load the choices.',
            current: 'Current',
        },
        es: {
            label: 'Ruta de navegación',
            change_category: 'Cambiar de categoría',
            change_module: 'Cambiar de módulo',
            change_record: 'Cambiar elemento: {name}',
            entity_search_placeholder: 'Buscar otro elemento...',
            entity_loading: 'Buscando...',
            entity_empty: 'No se encontró otro elemento.',
            entity_error: 'No se pudieron cargar las opciones.',
            current: 'Actual',
        },
    };

    Object.entries(expected).forEach(([locale, labels]) => {
        const messages = JSON.parse(source(`resources/js/i18n/modules/${locale}/workspace_hub.json`));
        assert.deepEqual(
            Object.fromEntries(Object.keys(labels).map((key) => [key, messages.workspace_hub?.breadcrumbs?.[key]])),
            labels,
            `${locale} must expose every breadcrumb navigation label`,
        );
    });
});

test('breadcrumb labels and sibling triggers form one split control loaded asynchronously', () => {
    const breadcrumbs = source('resources/js/Components/UI/AppBreadcrumbs.vue');
    const layout = source('resources/js/Layouts/AuthenticatedLayout.vue');

    assert.match(breadcrumbs, /const BreadcrumbSiblingMenu = defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/BreadcrumbSiblingMenu\.vue'\)\);/);
    assert.match(breadcrumbs, /const BreadcrumbEntitySwitcher = defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/BreadcrumbEntitySwitcher\.vue'\)\);/);
    assert.doesNotMatch(breadcrumbs, /import BreadcrumbSiblingMenu from/);
    assert.doesNotMatch(breadcrumbs, /import BreadcrumbEntitySwitcher from/);
    assert.match(layout, /const AppBreadcrumbs = defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/AppBreadcrumbs\.vue'\)\);/);
    assert.doesNotMatch(layout, /import AppBreadcrumbs from/);

    assert.match(breadcrumbs, /const hasEntitySwitcher = \(item\) => Boolean\(item\?\.entitySource\?\.href\);/);
    assert.match(breadcrumbs, /const hasSiblingMenu = \(item\) => Array\.isArray\(item\?\.siblings\) && item\.siblings\.length > 1;/);
    assert.match(breadcrumbs, /const hasLevelMenu = \(item\) => hasEntitySwitcher\(item\) \|\| hasSiblingMenu\(item\);/);
    assert.match(breadcrumbs, /<div\s+class="group inline-flex min-w-0 items-stretch rounded-full[\s\S]*?<component[\s\S]*?<BreadcrumbSiblingMenu[\s\S]*?<\/div>/);
    assert.match(breadcrumbs, /:is="item\.href && !item\.isCurrent \? Link : 'span'"/);
    assert.match(breadcrumbs, /<BreadcrumbSiblingMenu\s+v-if="hasSiblingMenu\(item\)"/);
    assert.match(breadcrumbs, /:items="item\.siblings"/);
    assert.match(breadcrumbs, /:label="item\.siblingsLabel \|\| item\.label"/);
    assert.match(breadcrumbs, /:current-key="item\.key"/);
    assert.match(breadcrumbs, /@select="emit\('select', \{ item, sibling: \$event \}\)"/);
    assert.match(breadcrumbs, /const navigationLabel = computed\(\(\) => props\.ariaLabel \|\| t\('workspace_hub\.breadcrumbs\.label'\)\);/);
    assert.match(breadcrumbs, /<nav[\s\S]*?:aria-label="navigationLabel"/);
});

test('every breadcrumb transition renders one exclusive static or interactive chevron', () => {
    const breadcrumbs = source('resources/js/Components/UI/AppBreadcrumbs.vue');
    const siblingMenu = source('resources/js/Components/UI/BreadcrumbSiblingMenu.vue');
    const entityMenu = source('resources/js/Components/UI/BreadcrumbEntitySwitcher.vue');
    const triggerVariant = /:trigger-variant="index < normalizedItems\.length - 1 \? 'separator' : 'trailing'"/g;

    assert.equal((breadcrumbs.match(triggerVariant) || []).length, 2);
    assert.equal((breadcrumbs.match(/v-if="index < normalizedItems\.length - 1 && !hasLevelMenu\(item\)"/g) || []).length, 1);
    assert.doesNotMatch(breadcrumbs, /index < normalizedItems\.length - 1 && !hasSiblingMenu\(item\)/);
    assert.match(breadcrumbs, /<BreadcrumbEntitySwitcher\s+v-if="hasEntitySwitcher\(item\)"/);
    assert.match(breadcrumbs, /<template v-else>[\s\S]*?<BreadcrumbSiblingMenu\s+v-if="hasSiblingMenu\(item\)"/);

    [siblingMenu, entityMenu].forEach((menu) => {
        assert.match(menu, /validator: \(value\) => \['separator', 'trailing'\]\.includes\(value\)/);
        assert.equal((menu.match(/v-if="triggerVariant === 'separator'"/g) || []).length, 1);
        assert.match(menu, /v-if="triggerVariant === 'separator'"[\s\S]*?<svg\s+v-else/);
    });
});

test('breadcrumb normalization exposes exactly one current page', () => {
    const breadcrumbs = source('resources/js/Components/UI/AppBreadcrumbs.vue');

    assert.match(breadcrumbs, /let currentIndex = filteredItems\.length - 1;/);
    assert.match(breadcrumbs, /for \(let index = filteredItems\.length - 1; index >= 0; index -= 1\)/);
    assert.match(breadcrumbs, /if \(filteredItems\[index\]\?\.current === true\) \{\s*currentIndex = index;\s*break;/);
    assert.match(breadcrumbs, /isCurrent: index === currentIndex,/);
    assert.equal((breadcrumbs.match(/:aria-current=/g) || []).length, 1);
    assert.match(breadcrumbs, /:aria-current="item\.isCurrent \? 'page' : undefined"/);
    assert.doesNotMatch(breadcrumbs, /aria-current[^\n]*siblings/);
});

test('sibling menus expose a complete ARIA and roving keyboard contract', () => {
    const menu = source('resources/js/Components/UI/BreadcrumbSiblingMenu.vue');

    assert.match(menu, /import \{ useFloatingMenu \} from '@\/Composables\/useFloatingMenu';/);
    assert.match(menu, /const menuId = `breadcrumb-siblings-\$\{useId\(\)\.replaceAll\(':', ''\)\}`;/);
    assert.match(menu, /useFloatingMenu\(\{ align: 'start', padding: 12, offset: 6 \}\)/);
    assert.match(menu, /<button\s+ref="toggleRef"\s+type="button"/);
    assert.match(menu, /:aria-label="label"/);
    assert.match(menu, /:aria-controls="menuId"/);
    assert.match(menu, /:aria-expanded="isOpen \? 'true' : 'false'"/);
    assert.match(menu, /aria-haspopup="menu"/);
    assert.match(menu, /role="menu"/);
    assert.match(menu, /aria-orientation="vertical"/);
    assert.match(menu, /role="menuitemradio"/);
    assert.match(menu, /:aria-checked="index === currentIndex \? 'true' : 'false'"/);
    assert.match(menu, /:tabindex="index === activeIndex \? 0 : -1"/);
    assert.match(menu, /data-breadcrumb-menu-item/);
    assert.match(menu, /workspace_hub\.breadcrumbs\.current/);

    ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', 'Escape'].forEach((key) => {
        assert.match(menu, new RegExp(`event\\.key === '${key}'`), `${key} must be handled`);
    });
    assert.match(menu, /event\.key === 'Enter' \|\| event\.key === ' '/);
    assert.match(menu, /const normalizedIndex = \(\(index % elements\.length\) \+ elements\.length\) % elements\.length;/);
    assert.match(menu, /focusItem\(focusedIndex \+ 1\)/);
    assert.match(menu, /focusItem\(focusedIndex - 1\)/);
    assert.match(menu, /elements\[focusedIndex\]\?\.click\(\)/);
    assert.match(menu, /const closeAndRestoreFocus = \(\) => \{\s*closeMenu\(\);\s*nextTick\(\(\) => toggleRef\.value\?\.focus\(\)\);/);
    assert.ok((menu.match(/aria-hidden="true"/g) || []).length >= 2, 'menu icons must remain decorative');
});

test('teleported sibling menus remain usable on narrow screens and with long labels', () => {
    const breadcrumbs = source('resources/js/Components/UI/AppBreadcrumbs.vue');
    const menu = source('resources/js/Components/UI/BreadcrumbSiblingMenu.vue');

    assert.match(menu, /<Teleport to="body">/);
    assert.match(menu, /class="fixed z-\[100\]/);
    assert.match(menu, /max-h-\[min\(24rem,calc\(100vh-1\.5rem\)\)\]/);
    assert.match(menu, /w-\[min\(18rem,calc\(100vw-1\.5rem\)\)\]/);
    assert.match(menu, /overflow-x-hidden overflow-y-auto overscroll-contain/);
    assert.match(menu, /<span class="min-w-0 flex-1 truncate">\{\{ item\.label \}\}<\/span>/);
    assert.match(breadcrumbs, /<span class="truncate">\s*\{\{ item\.label \}\}\s*<\/span>/);
    assert.doesNotMatch(menu, /hs-dropdown|data-hs-/);
});

test('sibling menus close on selection and Inertia navigation without leaking listeners', () => {
    const menu = source('resources/js/Components/UI/BreadcrumbSiblingMenu.vue');

    assert.match(menu, /import \{ Link, router \} from '@inertiajs\/vue3';/);
    assert.match(menu, /:is="item\.href \? Link : 'button'"/);
    assert.match(menu, /const selectItem = \(item\) => \{\s*closeMenu\(\);\s*if \(!item\.href\) \{\s*emit\('select', item\);/);
    assert.match(menu, /stopNavigationListener = router\.on\('start', closeMenu\);/);
    assert.match(menu, /onBeforeUnmount\(\(\) => \{\s*stopNavigationListener\?\.\(\);\s*\}\);/);
});

test('entity switchers are attached only to the thirteen supported show-page tails', () => {
    const resolver = source('resources/js/utils/workspaceBreadcrumbs.js');
    const backend = source('app/Services/WorkspaceBreadcrumbEntityService.php');
    const routes = source('routes/web.php');
    const expectedTypes = [
        'customer',
        'prospect',
        'service_request',
        'quote',
        'sale',
        'campaign',
        'employee',
        'work',
        'task',
        'invoice',
        'expense',
        'product',
        'plan_scan',
    ];
    const matrix = [
        {
            builder: 'buildCustomerModuleTail',
            next: 'buildRequestsModuleTail',
            type: 'customer',
            showRoutes: ['customer.show'],
            excludedRoutes: ['customer.create', 'customer.edit'],
        },
        {
            builder: 'buildRequestsModuleTail',
            next: 'buildServiceRequestsModuleTail',
            type: 'prospect',
            showRoutes: ['prospects.show', 'request.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildServiceRequestsModuleTail',
            next: 'buildQuotesModuleTail',
            type: 'service_request',
            showRoutes: ['service-requests.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildQuotesModuleTail',
            next: 'buildSalesModuleTail',
            type: 'quote',
            showRoutes: ['customer.quote.show'],
            excludedRoutes: ['customer.quote.create', 'customer.quote.edit'],
        },
        {
            builder: 'buildSalesModuleTail',
            next: 'buildCampaignsModuleTail',
            type: 'sale',
            showRoutes: ['sales.show'],
            excludedRoutes: ['sales.create', 'sales.edit'],
        },
        {
            builder: 'buildCampaignsModuleTail',
            next: 'buildPromotionsModuleTail',
            type: 'campaign',
            showRoutes: ['campaigns.show'],
            excludedRoutes: ['campaigns.edit'],
        },
        {
            builder: 'buildPerformanceModuleTail',
            next: 'buildJobsModuleTail',
            type: 'employee',
            showRoutes: ['performance.employee.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildJobsModuleTail',
            next: 'buildTasksModuleTail',
            type: 'work',
            showRoutes: ['work.show'],
            excludedRoutes: ['work.create', 'work.edit', 'work.proofs'],
        },
        {
            builder: 'buildTasksModuleTail',
            next: 'buildInvoicesModuleTail',
            type: 'task',
            showRoutes: ['task.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildInvoicesModuleTail',
            next: 'buildExpensesModuleTail',
            type: 'invoice',
            showRoutes: ['invoice.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildExpensesModuleTail',
            next: 'buildProductsModuleTail',
            type: 'expense',
            showRoutes: ['expense.show'],
            excludedRoutes: [],
        },
        {
            builder: 'buildProductsModuleTail',
            next: 'buildPlanScansModuleTail',
            type: 'product',
            showRoutes: ['product.show'],
            excludedRoutes: ['product.create', 'product.edit'],
        },
        {
            builder: 'buildPlanScansModuleTail',
            next: 'resolveModuleTail',
            type: 'plan_scan',
            showRoutes: ['plan-scans.show'],
            excludedRoutes: ['plan-scans.create'],
        },
    ];

    const backendTypesBlock = backend.match(/private const TYPES = \[([\s\S]*?)\];/)?.[1] || '';
    const backendTypes = [...backendTypesBlock.matchAll(/'([^']+)'/g)].map((match) => match[1]);
    assert.deepEqual(backendTypes, expectedTypes);
    assert.match(routes, /Route::get\('\/workspace\/breadcrumb-entities\/\{type\}', WorkspaceBreadcrumbEntityController::class\)[\s\S]*?->name\('workspace\.breadcrumb-entities\.index'\);/);
    assert.equal((resolver.match(/withEntitySwitcher\(/g) || []).length, expectedTypes.length);

    matrix.forEach(({ builder, next, type, showRoutes, excludedRoutes }) => {
        const tail = declarationSection(resolver, builder, next);

        assert.equal((tail.match(/withEntitySwitcher\(/g) || []).length, 1, `${builder} must expose one leaf switcher`);
        assert.match(tail, new RegExp(`\\n\\s*'${type}',\\n\\s*t,`));
        showRoutes.forEach((routeName) => {
            assert.match(tail, new RegExp(`route\\(\\)\\.current\\('${escapeRegExp(routeName)}'\\)`));
        });
        excludedRoutes.forEach((routeName) => {
            const branch = balancedBlockAfter(tail, `if (route().current('${routeName}')) {`);
            assert.doesNotMatch(branch, /withEntitySwitcher\(/, `${routeName} must not switch records`);
        });
    });

    const promotions = declarationSection(resolver, 'buildPromotionsModuleTail', 'buildPerformanceModuleTail');
    assert.doesNotMatch(promotions, /withEntitySwitcher\(/);
    assert.match(resolver, /entitySource: \{\s*href,\s*type,\s*currentKey: item\.key,\s*\}/);
    assert.match(resolver, /siblingsLabel: t\('workspace_hub\.breadcrumbs\.change_record', \{ name: item\.label \}\)/);
});

test('remote entity switcher is a lazy searchable dialog with bounded asynchronous states', () => {
    const switcher = source('resources/js/Components/UI/BreadcrumbEntitySwitcher.vue');
    const mountedBlock = balancedBlockAfter(switcher, 'onMounted(() => {');

    assert.match(switcher, /import \{ Link, router \} from '@inertiajs\/vue3';/);
    assert.match(switcher, /import axios from 'axios';/);
    assert.match(switcher, /useFloatingMenu\(\{ align: 'start', padding: 12, offset: 6 \}\)/);
    assert.match(switcher, /const dialogId = `breadcrumb-entities-\$\{componentId\}`;/);
    assert.match(switcher, /<Teleport to="body">/);
    assert.match(switcher, /role="dialog"/);
    assert.match(switcher, /<button\s+ref="toggleRef"\s+type="button"/);
    assert.match(switcher, /:aria-controls="dialogId"/);
    assert.match(switcher, /:aria-expanded="isOpen \? 'true' : 'false'"/);
    assert.match(switcher, /aria-haspopup="dialog"/);
    assert.match(switcher, /<label :for="searchId" class="sr-only">\{\{ label \}\}<\/label>/);
    assert.match(switcher, /v-model="query"\s+type="search"\s+autocomplete="off"/);
    assert.match(switcher, /:placeholder="t\('workspace_hub\.breadcrumbs\.entity_search_placeholder'\)"/);
    assert.match(switcher, /:aria-busy="isLoading \? 'true' : 'false'"/);
    assert.match(switcher, /v-if="isLoading"[\s\S]*?role="status"\s+aria-live="polite"\s+aria-atomic="true"/);
    assert.match(switcher, /v-else-if="hasError"[\s\S]*?role="alert"\s+aria-live="assertive"\s+aria-atomic="true"/);
    assert.match(switcher, /v-else-if="hasLoaded && entities\.length === 0"[\s\S]*?role="status"\s+aria-live="polite"\s+aria-atomic="true"/);
    assert.match(switcher, /<Link\s+v-for="entity in entities"[\s\S]*?:href="entity\.href"[\s\S]*?data-breadcrumb-entity/);
    assert.match(switcher, /v-if="entity\.current"[\s\S]*?workspace_hub\.breadcrumbs\.current/);
    assert.match(switcher, /String\(item\.key \?\? id\) === String\(props\.source\.currentKey\)/);

    assert.doesNotMatch(mountedBlock, /requestEntities\(/, 'mounting must not fetch entities');
    assert.match(switcher, /const openPopover = \(\) => \{[\s\S]*?if \(!hasLoaded\.value && !isLoading\.value\) \{\s*requestEntities\(\);/);
    assert.match(switcher, /if \(!isOpen\.value\) \{\s*return;\s*\}[\s\S]*?debounceTimer = setTimeout\(requestEntities, 250\);/);
    assert.match(switcher, /if \(query\.value\.trim\(\)\.length === 1\) \{\s*return;/);
    assert.match(switcher, /axios\.get\(sourceHref\.value, \{[\s\S]*?q: normalizedQuery \|\| undefined,/);
    assert.match(switcher, /const controller = new AbortController\(\);/);
    assert.match(switcher, /activeRequest\?\.abort\(\);/);
    assert.match(switcher, /signal: controller\.signal,/);
    assert.match(switcher, /if \(sequence !== requestSequence\) \{\s*return;/);
    assert.match(switcher, /const cleanupPendingWork = \(\) => \{\s*clearDebounce\(\);\s*abortRequest\(\);/);
    assert.match(switcher, /stopNavigationListener = router\.on\('start', \(\) => closePopover\(\)\);/);
    assert.match(switcher, /onBeforeUnmount\(\(\) => \{\s*stopNavigationListener\?\.\(\);\s*cleanupPendingWork\(\);\s*\}\);/);
    assert.match(switcher, /if \(event\.key === 'Escape'\)[\s\S]*?closePopover\(\{ restoreFocus: true \}\)/);
    assert.doesNotMatch(switcher, /hs-dropdown|data-hs-/);
});
