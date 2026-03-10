import { spawn, spawnSync } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, unlinkSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');
const publicPath = path.join(projectRoot, 'public');
const sqliteDir = path.join(projectRoot, 'storage', 'framework', 'testing');
const sqlitePath = path.join(sqliteDir, 'e2e.sqlite');
const viteHotPath = path.join(projectRoot, 'public', 'hot');
const routerPath = path.join(projectRoot, 'scripts', 'playwright-router.php');
const port = process.env.PLAYWRIGHT_E2E_PORT || '38103';
const appUrl = `http://127.0.0.1:${port}`;

mkdirSync(sqliteDir, { recursive: true });
if (!existsSync(sqlitePath)) {
    closeSync(openSync(sqlitePath, 'w'));
}
if (existsSync(viteHotPath)) {
    unlinkSync(viteHotPath);
}

const env = {
    ...process.env,
    APP_ENV: 'e2e',
    APP_DEBUG: 'true',
    APP_KEY: '0123456789abcdef0123456789abcdef',
    APP_URL: appUrl,
    APP_MAINTENANCE_DRIVER: 'file',
    CACHE_STORE: 'file',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: sqlitePath,
    MAIL_MAILER: 'array',
    PULSE_ENABLED: 'false',
    QUEUE_CONNECTION: 'sync',
    SESSION_DRIVER: 'file',
    TELESCOPE_ENABLED: 'false',
    LOG_CHANNEL: process.env.LOG_CHANNEL || 'stack',
};

const resolvePhpBinary = () => {
    if (process.env.PHP_BINARY) {
        return process.env.PHP_BINARY;
    }

    const herdPhp = process.platform === 'win32'
        ? path.join(process.env.USERPROFILE || '', '.config', 'herd', 'bin', 'php.bat')
        : null;

    if (herdPhp && existsSync(herdPhp)) {
        return herdPhp;
    }

    const locator = process.platform === 'win32' ? 'where' : 'which';
    const probe = spawnSync(locator, ['php'], {
        cwd: projectRoot,
        env,
        encoding: 'utf8',
    });

    if (probe.status === 0) {
        const resolved = probe.stdout
            .split(/\r?\n/)
            .map((line) => line.trim())
            .find(Boolean);

        if (resolved) {
            return resolved;
        }
    }

    return 'php';
};

const phpBinary = resolvePhpBinary();
const useShellForPhp = process.platform === 'win32' && /\.(bat|cmd)$/i.test(phpBinary);

const runPhp = (args) => new Promise((resolve, reject) => {
    const child = spawn(phpBinary, args, {
        cwd: projectRoot,
        env,
        stdio: 'inherit',
        shell: useShellForPhp,
    });

    child.on('error', reject);
    child.on('exit', (code) => {
        if (code === 0) {
            resolve();
            return;
        }

        reject(new Error(`php ${args.join(' ')} exited with code ${code}`));
    });
});

await runPhp(['artisan', 'optimize:clear']);
await runPhp(['artisan', 'migrate:fresh', '--seed', '--seeder=Database\\Seeders\\E2ESmokeSeeder', '--force']);

const server = spawn(phpBinary, ['-S', `127.0.0.1:${port}`, '-t', publicPath, routerPath], {
    cwd: projectRoot,
    env,
    stdio: 'inherit',
    shell: useShellForPhp,
});

const shutdown = () => {
    if (!server.killed) {
        server.kill('SIGTERM');
    }
};

process.on('SIGINT', () => {
    shutdown();
    process.exit(0);
});

process.on('SIGTERM', () => {
    shutdown();
    process.exit(0);
});

server.on('error', (error) => {
    console.error(error);
    process.exit(1);
});

server.on('exit', (code) => {
    process.exit(code ?? 0);
});
