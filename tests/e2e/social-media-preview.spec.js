import { expect, test } from '@playwright/test';
import { installLocalAppUi } from './helpers/local-app-ui.mjs';

const origin = 'https://pulse-preview.test';
test.use({ baseURL: origin, serviceWorkers: 'block', video: 'off' });
const accounts = ['facebook', 'instagram', 'linkedin'].map((platform, index) => ({
    id: index + 41, platform, label: `Compte ${platform}`, display_name: `Atelier ${platform}`,
    provider_label: platform, status: 'connected', is_active: true,
}));
const shared = {
    locale: 'fr', errors: {}, flash: {}, assistant: { enabled: false },
    auth: {
        user: { id: 90001, name: 'Compte de test', email: 'browser@example.test' },
        account: { owner_id: 90001, is_owner: true, is_client: false, company: { name: 'Atelier Pulse', type: 'services', features: { social: true } } },
    },
    access: { can_view: true, can_manage_posts: true, can_publish: true },
};
const post = {
    id: 90001, text: 'Notre vidéo présente les services de notre atelier.', status: 'draft', is_editable: true,
    selected_target_connection_ids: [41, 42, 43], targets: accounts, media_assets: [],
};

const createVideo = async (page) => Buffer.from(await page.evaluate(async () => {
    const canvas = document.createElement('canvas');
    canvas.width = 240;
    canvas.height = 426;
    const context = canvas.getContext('2d');
    const stream = canvas.captureStream(10);
    const recorder = new MediaRecorder(stream, { mimeType: 'video/webm' });
    const chunks = [];
    recorder.ondataavailable = (event) => chunks.push(event.data);
    const stopped = new Promise((resolve) => { recorder.onstop = resolve; });
    recorder.start();
    for (let frame = 0; frame < 4; frame++) {
        context.fillStyle = '#064e3b';
        context.fillRect(0, 0, 240, 426);
        context.fillStyle = 'white';
        context.font = 'bold 28px sans-serif';
        context.fillText('Pulse vidéo', 34, 190 + frame);
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    recorder.stop();
    await stopped;
    stream.getTracks().forEach((track) => track.stop());
    return [...new Uint8Array(await new Blob(chunks, { type: 'video/webm' }).arrayBuffer())];
}));

const install = (page, component, pathname, props, video) => installLocalAppUi(page, {
    origin, component, pathname, props: { ...shared, ...props },
    intercept: async ({ route, url }) => {
        if (url.pathname === '/preview.webm') {
            await route.fulfill({ contentType: 'video/webm', body: video });
            return true;
        }
        if (url.pathname === '/broken.webm') {
            await route.fulfill({ status: 404, body: 'Not found' });
            return true;
        }
        if (url.pathname === '/cover.png') {
            await route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jLxkAAAAASUVORK5CYII=', 'base64') });
            return true;
        }
        if (!url.pathname.startsWith('/build/') && /notifications|messag|presence|onboarding|support-chat/.test(url.pathname)) {
            await route.fulfill({ json: { data: [], notifications: [], unread_count: 0 } });
            return true;
        }
        return false;
    },
});
const preview = (page, platform) => page.getByRole('article', { name: `Aperçu ${platform}`, exact: true });

test('an uploaded video plays before saving and survives selected-platform changes', async ({ page }, testInfo) => {
    const video = await createVideo(page);
    const requests = await install(page, 'Social/Composer', '/social/composer', {
        connected_accounts: accounts, drafts: [post], selected_draft_id: post.id,
    }, video);
    await page.goto('/social/composer');
    await page.locator('input[type="file"][multiple]').setInputFiles({ name: 'presentation.webm', mimeType: 'video/webm', buffer: video });
    const player = preview(page, 'Facebook').locator('video');
    await expect(player).toHaveAttribute('src', /^blob:/);
    await expect.poll(() => player.evaluate((element) => element.videoWidth)).toBe(240);
    await player.evaluate((element) => { element.muted = true; return element.play(); });
    await expect.poll(() => player.evaluate((element) => element.currentTime)).toBeGreaterThan(0);
    const previousPlayer = await player.elementHandle();
    await expect(page.getByText('Image ou vidéo requise pour Instagram', { exact: true })).toHaveCount(0);
    await page.getByRole('group', { name: 'Plateforme de l’aperçu' }).getByRole('button', { name: 'Instagram · Atelier instagram', exact: true }).click();
    expect(await previousPlayer.evaluate((element) => element.paused)).toBe(true);
    await expect(preview(page, 'Instagram').locator('video')).toHaveAttribute('src', /^blob:/);
    await expect(preview(page, 'Instagram')).toContainText(post.text);
    await preview(page, 'Instagram').screenshot({ path: testInfo.outputPath('instagram-video.png') });
    await page.getByRole('group', { name: 'Plateforme de l’aperçu' }).getByRole('button', { name: 'LinkedIn · Atelier linkedin', exact: true }).click();
    await expect(preview(page, 'LinkedIn').locator('video')).toBeVisible();
    await page.getByRole('button', { name: /Compte linkedin.*Atelier linkedin/u }).click();
    await expect(preview(page, 'Facebook')).toBeVisible();
    await expect(page.getByRole('group', { name: 'Plateforme de l’aperçu' }).getByRole('button')).toHaveCount(2);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('history displays saved video and mixed media at mobile width in dark mode', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.setItem('hs_theme', 'dark'));
    const video = await createVideo(page);
    const requests = await install(page, 'Social/History', '/social/history', {
        posts: [{ ...post, media_assets: [{ type: 'video', url: '/preview.webm' }, { type: 'image', url: '/cover.png' }] }],
    }, video);
    await page.goto('/social/history');
    await expect.poll(() => preview(page, 'Facebook').locator('video').evaluate((element) => element.videoWidth)).toBe(240);
    await page.getByRole('button', { name: 'Média suivant', exact: true }).click();
    await expect(preview(page, 'Facebook').locator('img')).toHaveAttribute('src', '/cover.png');
    await page.getByRole('button', { name: 'Média précédent', exact: true }).click();
    await page.getByRole('button', { name: 'Instagram · Atelier instagram', exact: true }).click();
    const card = preview(page, 'Instagram');
    await expect(card.locator('video')).toBeVisible();
    const box = await card.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    await card.screenshot({ path: testInfo.outputPath('history-video-mobile-dark.png') });
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('an unreadable saved video shows an actionable fallback', async ({ page }) => {
    const requests = await install(page, 'Social/History', '/social/history', {
        posts: [{ ...post, media_assets: [{ type: 'video', url: '/broken.webm' }] }],
    });
    await page.goto('/social/history');
    await expect(preview(page, 'Facebook').getByRole('status')).toContainText('Ce média ne peut pas être affiché');
    await expect(preview(page, 'Facebook').getByRole('link', { name: 'Ouvrir le média' })).toHaveAttribute('href', '/broken.webm');
    expect(requests.pageErrors).toEqual([]);
});
