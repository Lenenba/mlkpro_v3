#!/usr/bin/env node

import { spawnSync } from 'node:child_process';

const result = spawnSync('codex', ['mcp', 'list', '--json'], {
    encoding: 'utf8',
    shell: process.platform === 'win32',
    windowsHide: true,
});

if (result.error?.code === 'ENOENT') {
    console.error('Codex CLI est introuvable. Vérifie que `codex` est disponible dans le PATH.');
    process.exit(127);
}

if (result.error) {
    console.error(`Impossible de lancer Codex CLI : ${result.error.message}`);
    process.exit(1);
}

if (result.status !== 0) {
    const errorMessage = result.stderr.trim() || 'La lecture des serveurs MCP a échoué.';
    console.error(errorMessage);
    process.exit(result.status ?? 1);
}

let servers;

try {
    servers = JSON.parse(result.stdout);
} catch {
    console.error("Codex CLI n'a pas retourné une réponse JSON valide.");
    process.exit(1);
}

if (!Array.isArray(servers)) {
    console.error('Format MCP inattendu : une liste de serveurs était attendue.');
    process.exit(1);
}

if (servers.length === 0) {
    console.log('Aucun serveur MCP configuré.');
    process.exit(0);
}

const authLabels = {
    authenticated: 'Connectée',
    not_authenticated: 'À connecter',
    unauthenticated: 'À connecter',
    unknown: 'Inconnue',
    unsupported: 'Non applicable',
};

const transportLabels = {
    stdio: 'STDIO',
    streamable_http: 'HTTP',
};

const rows = servers.map((server) => [
    String(server.name ?? '—'),
    server.enabled ? '● Actif' : '○ Désactivé',
    authLabels[server.auth_status] ?? String(server.auth_status ?? '—'),
    transportLabels[server.transport?.type] ?? String(server.transport?.type ?? '—').toUpperCase(),
]);

const headers = ['MCP', 'ÉTAT', 'AUTH', 'TRANSPORT'];
const widths = headers.map((header, columnIndex) => Math.max(
    header.length,
    ...rows.map((row) => row[columnIndex].length),
));

const formatRow = (row) => row
    .map((cell, columnIndex) => cell.padEnd(widths[columnIndex]))
    .join('  ')
    .trimEnd();

console.log(formatRow(headers));
console.log(widths.map((width) => '─'.repeat(width)).join('  '));

for (const row of rows) {
    console.log(formatRow(row));
}

const activeCount = servers.filter((server) => server.enabled).length;
const inactiveCount = servers.length - activeCount;
const pluralize = (count, singular, plural) => `${count} ${count === 1 ? singular : plural}`;

console.log('');
console.log([
    pluralize(servers.length, 'serveur MCP', 'serveurs MCP'),
    pluralize(activeCount, 'actif', 'actifs'),
    pluralize(inactiveCount, 'désactivé', 'désactivés'),
].join(' · '));
