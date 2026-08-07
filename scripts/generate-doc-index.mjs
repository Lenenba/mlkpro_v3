import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..');
const docsRoot = path.join(repositoryRoot, 'docs');
const configPath = path.join(docsRoot, 'document-status.json');
const indexFilename = '00_INDEX.md';
const checkOnly = process.argv.includes('--check');
const supportedExtensions = new Set(['.md', '.json', '.csv', '.html']);

const documentStatusLabels = {
    draft: 'Brouillon',
    complete: 'Complet',
    reference: 'Référence',
    archived: 'Archivé',
    unclassified: 'À classer',
};

const deliveryGroups = [
    ['in_progress', 'En cours'],
    ['blocked', 'Bloqué'],
    ['planned', 'À faire'],
    ['done', 'Terminé'],
    ['not_applicable', 'Références actives'],
    ['archived', 'Archivés'],
    ['unclassified', 'À classer'],
];

const sourceLabels = {
    override: 'Classement manuel',
    filename: 'Nom du fichier',
    filetype: 'Type de fichier',
    unknown: 'À qualifier',
};

function toPosix(value) {
    return value.split(path.sep).join('/');
}

function repositoryPath(absolutePath) {
    return toPosix(path.relative(repositoryRoot, absolutePath));
}

function normalizeText(value) {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function escapeTableCell(value) {
    return String(value ?? '')
        .replace(/\r?\n/g, ' ')
        .replace(/\|/g, '\\|')
        .trim();
}

function readConfiguration() {
    if (!fs.existsSync(configPath)) {
        return { documents: {} };
    }

    const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        throw new Error('docs/document-status.json doit contenir un objet JSON.');
    }

    if (
        parsed.documents !== undefined
        && (
            !parsed.documents
            || typeof parsed.documents !== 'object'
            || Array.isArray(parsed.documents)
        )
    ) {
        throw new Error('docs/document-status.json: documents doit contenir un objet JSON.');
    }

    return { documents: parsed.documents ?? {} };
}

function collectDocumentationFiles() {
    const files = [];
    const directories = [];

    function visit(directory) {
        directories.push(directory);

        const entries = fs.readdirSync(directory, { withFileTypes: true })
            .sort((left, right) => left.name.localeCompare(right.name, 'fr'));

        for (const entry of entries) {
            const absolutePath = path.join(directory, entry.name);

            if (entry.isDirectory()) {
                visit(absolutePath);
                continue;
            }

            if (!entry.isFile()) {
                continue;
            }

            if (entry.name === indexFilename || absolutePath === configPath) {
                continue;
            }

            if (supportedExtensions.has(path.extname(entry.name).toLowerCase())) {
                files.push(absolutePath);
            }
        }
    }

    visit(docsRoot);

    return { files, directories };
}

function readGitDates() {
    const created = new Map();
    const updated = new Map();
    let currentDate = null;

    let output = '';
    try {
        output = execFileSync(
            'git',
            ['log', '--date=short', '--format=@@%ad', '--name-only', '--', 'docs'],
            {
                cwd: repositoryRoot,
                encoding: 'utf8',
                stdio: ['ignore', 'pipe', 'ignore'],
            },
        );
    } catch {
        return { created, updated };
    }

    for (const rawLine of output.split(/\r?\n/)) {
        const line = rawLine.trim();

        if (line.startsWith('@@')) {
            currentDate = line.slice(2);
            continue;
        }

        if (!currentDate || !line.startsWith('docs/')) {
            continue;
        }

        if (!updated.has(line)) {
            updated.set(line, currentDate);
        }

        created.set(line, currentDate);
    }

    return { created, updated };
}

function extractTitle(content, extension, absolutePath) {
    if (extension === '.md') {
        const heading = content.split(/\r?\n/).find((line) => /^#\s+/.test(line));
        if (heading) {
            return heading.replace(/^#\s+/, '').trim();
        }
    }

    if (extension === '.html') {
        const match = content.match(/<title>([^<]+)<\/title>/i);
        if (match) {
            return match[1].trim();
        }
    }

    return path.basename(absolutePath);
}

function classifyByFilename(absolutePath) {
    const extension = path.extname(absolutePath).toLowerCase();
    const filename = normalizeText(path.basename(absolutePath)).replace(/[^a-z0-9]+/g, '_');

    if (extension !== '.md') {
        return {
            document_status: 'reference',
            delivery_status: 'not_applicable',
            source: 'filetype',
        };
    }

    if (/archive/.test(filename)) {
        return {
            document_status: 'archived',
            delivery_status: 'archived',
            source: 'filename',
        };
    }

    if (/readme|guide|runbook|workflow|technical_design|documentation_technique|architecture|setup|sources|cahier_des_charges|analyse/.test(filename)) {
        return {
            document_status: 'reference',
            delivery_status: 'not_applicable',
            source: 'filename',
        };
    }

    return {
        document_status: 'unclassified',
        delivery_status: 'unclassified',
        source: 'unknown',
    };
}

function validateOverride(documentPath, override) {
    const allowedDocumentStatuses = new Set(Object.keys(documentStatusLabels));
    const allowedDeliveryStatuses = new Set(deliveryGroups.map(([status]) => status));

    if (!override || typeof override !== 'object' || Array.isArray(override)) {
        throw new Error(`${documentPath}: le classement doit contenir un objet JSON.`);
    }

    if (!allowedDocumentStatuses.has(override.document_status)) {
        throw new Error(`${documentPath}: document_status invalide.`);
    }

    if (!allowedDeliveryStatuses.has(override.delivery_status)) {
        throw new Error(`${documentPath}: delivery_status invalide.`);
    }

    for (const field of ['created_at', 'updated_at']) {
        if (override[field] !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(override[field])) {
            throw new Error(`${documentPath}: ${field} doit respecter le format YYYY-MM-DD.`);
        }
    }

    if (
        override.created_at
        && override.updated_at
        && override.updated_at < override.created_at
    ) {
        throw new Error(`${documentPath}: updated_at ne peut pas preceder created_at.`);
    }
}

function classifyDocument(documentPath, content, override) {
    const openTasks = (content.match(/^\s*[-*]\s+\[ \]/gm) ?? []).length;
    const completedTasks = (content.match(/^\s*[-*]\s+\[[xX]\]/gm) ?? []).length;

    if (override) {
        validateOverride(documentPath, override);
        return {
            document_status: override.document_status,
            delivery_status: override.delivery_status,
            note: override.note ?? '',
            source: 'override',
            open_tasks: openTasks,
            completed_tasks: completedTasks,
        };
    }

    const filenameClassification = classifyByFilename(documentPath);

    return {
        ...filenameClassification,
        note: openTasks > 0
            ? `${openTasks} validation(s) ouverte(s); statut de livraison a qualifier`
            : '',
        open_tasks: openTasks,
        completed_tasks: completedTasks,
    };
}

function groupFor(document) {
    if (document.document_status === 'archived') {
        return 'archived';
    }

    return document.delivery_status;
}

function relativeMarkdownLink(fromDirectory, targetPath, label) {
    const relative = toPosix(path.relative(fromDirectory, targetPath));
    const encoded = encodeURI(relative).replace(/#/g, '%23');
    return `[${escapeTableCell(label)}](${encoded})`;
}

function compareByCreatedDescending(left, right) {
    return right.created_at.localeCompare(left.created_at)
        || right.updated_at.localeCompare(left.updated_at)
        || left.path.localeCompare(right.path, 'fr');
}

function summarize(documents) {
    const counts = Object.fromEntries(deliveryGroups.map(([status]) => [status, 0]));

    for (const document of documents) {
        counts[groupFor(document)] += 1;
    }

    return counts;
}

function renderSummaryTable(documents) {
    const counts = summarize(documents);
    const lines = [
        '| En cours | Bloqués | À faire | Terminés | Références | Archivés | À classer | Total |',
        '| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        `| ${counts.in_progress} | ${counts.blocked} | ${counts.planned} | ${counts.done} | ${counts.not_applicable} | ${counts.archived} | ${counts.unclassified} | ${documents.length} |`,
    ];

    return lines;
}

function renderDocumentGroups(documents, indexDirectory) {
    const lines = [];

    for (const [status, heading] of deliveryGroups) {
        const matching = documents
            .filter((document) => groupFor(document) === status)
            .sort(compareByCreatedDescending);

        lines.push(`## ${heading}`, '');

        if (matching.length === 0) {
            lines.push('_Aucun document._', '');
            continue;
        }

        lines.push(
            '| Créé le | Modifié le | Document | Titre | État du document | Source | Note |',
            '| --- | --- | --- | --- | --- | --- | --- |',
        );

        for (const document of matching) {
            const absolutePath = path.join(repositoryRoot, ...document.path.split('/'));
            lines.push(`| ${document.created_at} | ${document.updated_at} | ${relativeMarkdownLink(indexDirectory, absolutePath, path.basename(document.path))} | ${escapeTableCell(document.title)} | ${documentStatusLabels[document.document_status]} | ${sourceLabels[document.source]} | ${escapeTableCell(document.note)} |`);
        }

        lines.push('');
    }

    return lines;
}

function directChildren(directory, allDirectories) {
    return allDirectories
        .filter((candidate) => candidate !== directory && path.dirname(candidate) === directory)
        .sort((left, right) => left.localeCompare(right, 'fr'));
}

function documentsBelow(directory, documents) {
    const prefix = `${repositoryPath(directory)}/`;
    return documents.filter((document) => document.path.startsWith(prefix));
}

function renderFolderIndex(directory, allDirectories, documents) {
    const directoryPath = repositoryPath(directory);
    const directDocuments = documents.filter((document) => path.dirname(document.path) === directoryPath);
    const children = directChildren(directory, allDirectories);
    const isRoot = directory === docsRoot;
    const lines = [
        `# Index - ${directoryPath}`,
        '',
        '> Fichier généré par `npm run docs:index`. Modifier les statuts dans `docs/document-status.json`, puis régénérer les index.',
        '>',
        '> Les dates correspondent aux premiers et derniers commits Git connus. Elles sont approximatives lorsque Git ne conserve pas l’historique complet du fichier.',
        '',
        'Le suivi et l’état du document sont séparés : un document peut être complet alors que sa livraison reste en cours. Dans chaque section, les documents sont triés par date de création décroissante. `À classer` signifie qu’aucune preuve suffisante ne permet encore de déclarer le travail terminé ou en cours.',
        '',
    ];

    if (!isRoot) {
        lines.push(`${relativeMarkdownLink(directory, path.join(docsRoot, indexFilename), 'Retour a l index principal')}`, '');
    }

    if (isRoot) {
        const latestCreated = [...documents].sort(compareByCreatedDescending).slice(0, 20);
        const latestUpdated = [...documents]
            .sort((left, right) => right.updated_at.localeCompare(left.updated_at)
                || right.created_at.localeCompare(left.created_at)
                || left.path.localeCompare(right.path, 'fr'))
            .slice(0, 20);

        lines.push('## Vue globale', '', ...renderSummaryTable(documents), '');
        lines.push('## Derniers documents créés', '');
        lines.push('| Créé le | Modifié le | Suivi | Document |', '| --- | --- | --- | --- |');
        for (const document of latestCreated) {
            const absolutePath = path.join(repositoryRoot, ...document.path.split('/'));
            const groupLabel = deliveryGroups.find(([status]) => status === groupFor(document))?.[1] ?? 'A classer';
            lines.push(`| ${document.created_at} | ${document.updated_at} | ${groupLabel} | ${relativeMarkdownLink(directory, absolutePath, document.path.replace(/^docs\//, ''))} |`);
        }
        lines.push('');

        lines.push('## Derniers documents modifiés', '');
        lines.push('| Modifié le | Créé le | Suivi | Document |', '| --- | --- | --- | --- |');
        for (const document of latestUpdated) {
            const absolutePath = path.join(repositoryRoot, ...document.path.split('/'));
            const groupLabel = deliveryGroups.find(([status]) => status === groupFor(document))?.[1] ?? 'A classer';
            lines.push(`| ${document.updated_at} | ${document.created_at} | ${groupLabel} | ${relativeMarkdownLink(directory, absolutePath, document.path.replace(/^docs\//, ''))} |`);
        }
        lines.push('');
    } else {
        lines.push('## Résumé des fichiers directs', '', ...renderSummaryTable(directDocuments), '');
    }

    if (children.length > 0) {
        lines.push('## Sous-dossiers', '');
        lines.push('| Dossier | En cours | Bloqués | À faire | Terminés | Références | Archivés | À classer | Total |', '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |');

        for (const child of children) {
            const childDocuments = documentsBelow(child, documents);
            const counts = summarize(childDocuments);
            lines.push(`| ${relativeMarkdownLink(directory, path.join(child, indexFilename), path.basename(child))} | ${counts.in_progress} | ${counts.blocked} | ${counts.planned} | ${counts.done} | ${counts.not_applicable} | ${counts.archived} | ${counts.unclassified} | ${childDocuments.length} |`);
        }

        lines.push('');
    }

    lines.push(...renderDocumentGroups(directDocuments, directory));

    return `${lines.join('\n').trim()}\n`;
}

function main() {
    const configuration = readConfiguration();
    const { files, directories } = collectDocumentationFiles();
    const gitDates = readGitDates();
    const indexedPaths = new Set(files.map((absolutePath) => repositoryPath(absolutePath)));

    for (const documentPath of Object.keys(configuration.documents)) {
        if (!indexedPaths.has(documentPath)) {
            throw new Error(`${documentPath}: le classement pointe vers un document inexistant ou non indexe.`);
        }
    }

    const documents = files.map((absolutePath) => {
        const documentPath = repositoryPath(absolutePath);
        const extension = path.extname(absolutePath).toLowerCase();
        const content = fs.readFileSync(absolutePath, 'utf8');
        const override = configuration.documents[documentPath];
        const classification = classifyDocument(
            documentPath,
            content,
            override,
        );
        const createdAt = override?.created_at ?? gitDates.created.get(documentPath);
        const updatedAt = override?.updated_at ?? gitDates.updated.get(documentPath);

        if (!createdAt || !updatedAt) {
            throw new Error(`${documentPath}: dates Git absentes; ajouter created_at et updated_at dans docs/document-status.json.`);
        }

        return {
            path: documentPath,
            title: extractTitle(content, extension, absolutePath),
            created_at: createdAt,
            updated_at: updatedAt,
            ...classification,
        };
    });

    const staleIndexes = [];

    for (const directory of directories) {
        const indexPath = path.join(directory, indexFilename);
        const expected = renderFolderIndex(directory, directories, documents);
        const current = fs.existsSync(indexPath) ? fs.readFileSync(indexPath, 'utf8') : null;

        if (current === expected) {
            continue;
        }

        if (checkOnly) {
            staleIndexes.push(repositoryPath(indexPath));
            continue;
        }

        fs.writeFileSync(indexPath, expected, 'utf8');
    }

    if (checkOnly && staleIndexes.length > 0) {
        console.error('Index documentaires à régénérer:');
        for (const indexPath of staleIndexes) {
            console.error(`- ${indexPath}`);
        }
        process.exitCode = 1;
        return;
    }

    const counts = summarize(documents);
    console.log(`Documentation indexée: ${documents.length} fichier(s), ${directories.length} dossier(s).`);
    console.log(`En cours: ${counts.in_progress}; bloqués: ${counts.blocked}; à faire: ${counts.planned}; terminés: ${counts.done}; références: ${counts.not_applicable}; archivés: ${counts.archived}; à classer: ${counts.unclassified}.`);
}

main();
