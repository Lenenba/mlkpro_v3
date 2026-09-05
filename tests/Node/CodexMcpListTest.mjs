import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmod, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { delimiter, dirname, join, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const scriptPath = join(repositoryRoot, 'scripts/codex-mcp-list.mjs');

async function createFakeCodex({ stdout = '', stderr = '', status = 0 }) {
    const directory = await mkdtemp(join(tmpdir(), 'codex-mcp-list-'));
    const fixtureSource = [
        `process.stdout.write(${JSON.stringify(stdout)});`,
        `process.stderr.write(${JSON.stringify(stderr)});`,
        `process.exit(${status});`,
        '',
    ].join('\n');

    if (process.platform === 'win32') {
        const fixturePath = join(directory, 'fake-codex.mjs');
        await writeFile(fixturePath, fixtureSource);
        await writeFile(
            join(directory, 'codex.cmd'),
            `@echo off\r\n"${process.execPath}" "${fixturePath}" %*\r\n`,
        );
    } else {
        const executablePath = join(directory, 'codex');
        await writeFile(executablePath, `#!/usr/bin/env node\n${fixtureSource}`);
        await chmod(executablePath, 0o755);
    }

    return directory;
}

function runScript(fakeBinaryDirectory, { inheritPath = true } = {}) {
    return spawnSync(process.execPath, [scriptPath], {
        cwd: repositoryRoot,
        encoding: 'utf8',
        env: {
            ...process.env,
            PATH: [fakeBinaryDirectory, inheritPath ? process.env.PATH : null]
                .filter(Boolean)
                .join(delimiter),
        },
    });
}

test('prints a compact MCP summary without commands or environment variables', async () => {
    const secret = 'must-not-be-printed';
    const directory = await createFakeCodex({
        stdout: JSON.stringify([
            {
                name: 'laravel-boost',
                enabled: true,
                auth_status: 'unsupported',
                transport: {
                    type: 'stdio',
                    command: 'php',
                    env: { PRIVATE_TOKEN: secret },
                },
            },
            {
                name: 'nightwatch',
                enabled: false,
                auth_status: 'not_authenticated',
                transport: {
                    type: 'streamable_http',
                    url: 'https://nightwatch.example.test/mcp',
                },
            },
        ]),
    });

    try {
        const result = runScript(directory);

        assert.equal(result.status, 0);
        assert.match(result.stdout, /laravel-boost\s+● Actif\s+Non applicable\s+STDIO/);
        assert.match(result.stdout, /nightwatch\s+○ Désactivé\s+À connecter\s+HTTP/);
        assert.match(result.stdout, /2 serveurs MCP · 1 actif · 1 désactivé/);
        assert.doesNotMatch(result.stdout, /must-not-be-printed|PRIVATE_TOKEN|nightwatch\.example\.test|php/);
        assert.equal(result.stderr, '');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('forwards a Codex CLI failure and preserves its exit status', async () => {
    const directory = await createFakeCodex({
        stderr: 'MCP configuration unavailable\n',
        status: 9,
    });

    try {
        const result = runScript(directory);

        assert.equal(result.status, 9);
        assert.equal(result.stdout, '');
        assert.equal(result.stderr, 'MCP configuration unavailable\n');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('rejects malformed Codex CLI output', async () => {
    const directory = await createFakeCodex({ stdout: '{not-json}' });

    try {
        const result = runScript(directory);

        assert.equal(result.status, 1);
        assert.equal(result.stdout, '');
        assert.match(result.stderr, /n'a pas retourné une réponse JSON valide/);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('rejects a Codex CLI payload that is not a server list', async () => {
    const directory = await createFakeCodex({
        stdout: JSON.stringify({ name: 'laravel-boost' }),
    });

    try {
        const result = runScript(directory);

        assert.equal(result.status, 1);
        assert.equal(result.stdout, '');
        assert.match(result.stderr, /une liste de serveurs était attendue/);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('reports an empty MCP configuration without failing', async () => {
    const directory = await createFakeCodex({ stdout: '[]' });

    try {
        const result = runScript(directory);

        assert.equal(result.status, 0);
        assert.equal(result.stdout, 'Aucun serveur MCP configuré.\n');
        assert.equal(result.stderr, '');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('returns 127 when Codex CLI is unavailable', {
    skip: process.platform === 'win32',
}, async () => {
    const directory = await mkdtemp(join(tmpdir(), 'codex-mcp-list-empty-'));

    try {
        const result = runScript(directory, { inheritPath: false });

        assert.equal(result.status, 127);
        assert.equal(result.stdout, '');
        assert.match(result.stderr, /Codex CLI est introuvable/);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});
