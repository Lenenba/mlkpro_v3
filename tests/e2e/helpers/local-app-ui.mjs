import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const projectRoot = process.cwd();
let ziggyRoutes;

const loadZiggy = (origin) => {
    if (!ziggyRoutes) {
        const routes = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
            'artisan', 'route:list', '--json', '--except-vendor', '--no-interaction',
        ], { cwd: projectRoot, encoding: 'utf8' }));
        ziggyRoutes = Object.fromEntries(routes.filter((route) => route.name).map((route) => [
            route.name, { uri: route.uri, methods: route.method.split('|') },
        ]));
    }
    return { url: origin, port: null, defaults: {}, routes: ziggyRoutes };
};

const escapeAttribute = (value) => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');

// Runs the production bundles against synthetic HTTP responses, without a server or database.
export async function installLocalAppUi(page, {
    origin, pathname, component, props, intercept, observations = {}, ziggyGroup = 'app',
}) {
    const manifest = JSON.parse(fs.readFileSync(path.join(projectRoot, 'public/build/manifest.json'), 'utf8'));
    const appAsset = manifest['resources/js/app.js'];
    const ziggy = loadZiggy(origin);
    let pendingReload = null;
    const requests = {
        assets: [], inertia: [], partialData: [], unexpected: [], pageErrors: [], ...observations,
        holdNextReload() {
            let started;
            let release;
            const gate = {
                started: new Promise((resolve) => { started = resolve; }),
                released: new Promise((resolve) => { release = resolve; }),
                release: () => release(),
                markStarted: () => started(),
            };
            pendingReload = gate;
            return gate;
        },
    };
    page.on('pageerror', (error) => requests.pageErrors.push(error.message));
    await page.context().addCookies([{
        name: 'mlk_cookie_prefs_v1',
        value: encodeURIComponent(JSON.stringify({ essential: true, analytics: false })),
        url: origin,
    }]);

    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin === origin) {
            if (await intercept?.({ route, request, url, ziggy, requests })) {
                return;
            }
            if (request.method() === 'GET') {
                if (url.pathname === '/fixture-ziggy.js') {
                    await route.fulfill({
                        path: path.join(projectRoot, 'vendor/tightenco/ziggy/dist/route.umd.js'),
                        contentType: 'application/javascript',
                    });
                    return;
                }
                if (/\.(?:js|css|svg|png|jpg|jpeg|webp|avif|ico|woff2?)$/u.test(url.pathname)) {
                    const file = path.resolve(projectRoot, 'public', `.${url.pathname}`);
                    if (file.startsWith(`${projectRoot}/public/`) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                        requests.assets.push(url.pathname);
                        await route.fulfill({ path: file });
                        return;
                    }
                }
                if (url.pathname === pathname) {
                    const pageProps = typeof props === 'function' ? props(url) : props;
                    const payload = { component, props: pageProps, url: url.pathname + url.search, version: 'fixture' };
                    if (request.headers()['x-inertia']) {
                        requests.inertia.push(url);
                        const only = request.headers()['x-inertia-partial-data']?.split(',');
                        requests.partialData.push({ url, only: only || [] });
                        if (only?.length) {
                            payload.props = Object.fromEntries(Object.entries(pageProps).filter(([key]) => only.includes(key)));
                        }
                        const gate = pendingReload;
                        pendingReload = null;
                        if (gate) {
                            gate.markStarted();
                            await gate.released;
                        }
                        await route.fulfill({ headers: { 'X-Inertia': 'true' }, json: payload });
                        return;
                    }
                    const css = (appAsset.css || []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('');
                    await route.fulfill({ contentType: 'text/html', body: `<!doctype html>
                        <html lang="${pageProps.locale || 'fr'}" data-ziggy-group="${ziggyGroup}"><head><meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <meta name="csrf-token" content="fixture">${css}
                        <script>window.Ziggy=${JSON.stringify(ziggy)};
                        document.documentElement.classList.add(localStorage.getItem('hs_theme') || 'default');</script>
                        <script src="/fixture-ziggy.js"></script>
                        <script type="module" src="/build/${appAsset.file}"></script></head>
                        <body class="font-sans antialiased bg-stone-50 text-stone-900 overflow-x-hidden dark:bg-neutral-950 dark:text-neutral-100">
                        <div id="app" data-page="${escapeAttribute(JSON.stringify(payload))}"></div></body></html>` });
                    return;
                }
            }
        }
        requests.unexpected.push(`${request.method()} ${url.pathname}`);
        await route.abort();
    });
    return requests;
}
