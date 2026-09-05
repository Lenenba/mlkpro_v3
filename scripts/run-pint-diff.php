<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pintProxy = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pint';

if (! is_file($pintProxy) || ! is_readable($pintProxy)) {
    fwrite(STDERR, "Unable to locate the readable Pint PHP proxy.\n");
    exit(1);
}

function runCommand(string $command, ?array &$output = null): int
{
    $output = [];
    exec($command.' 2>&1', $output, $code);

    return $code;
}

/**
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runProcess(string $command): array
{
    $stderrStream = tmpfile();
    if ($stderrStream === false) {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Unable to create a temporary stream for the Git process.',
        ];
    }

    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => $stderrStream,
        ],
        $pipes,
        dirname(__DIR__)
    );

    if (! is_resource($process)) {
        fclose($stderrStream);

        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Unable to start the Git process.',
        ];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    rewind($stderrStream);
    $stderr = (string) stream_get_contents($stderrStream);
    fclose($stderrStream);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function gitFileList(string $command, string $description): array
{
    $result = runProcess($command);

    if ($result['exit_code'] !== 0) {
        fwrite(STDERR, "Unable to determine {$description}.\n");
        if (trim($result['stderr']) !== '') {
            fwrite(STDERR, trim($result['stderr'])."\n");
        }

        exit(1);
    }

    return array_values(array_unique(array_filter(
        explode("\0", $result['stdout']),
        static fn (string $path): bool => $path !== ''
    )));
}

/**
 * @return array{
 *     unstaged: array<int, string>,
 *     staged: array<int, string>,
 *     untracked: array<int, string>,
 *     unstaged_deleted: array<int, string>,
 *     staged_deleted: array<int, string>
 * }
 */
function phpFileChanges(): array
{
    return [
        'unstaged' => gitFileList('git diff --name-only -z -- "*.php"', 'unstaged PHP files'),
        'staged' => gitFileList('git diff --cached --name-only -z -- "*.php"', 'staged PHP files'),
        'untracked' => gitFileList('git ls-files --others --exclude-standard -z -- "*.php"', 'untracked PHP files'),
        'unstaged_deleted' => gitFileList(
            'git diff --name-only -z --diff-filter=D -- "*.php"',
            'unstaged deleted PHP files'
        ),
        'staged_deleted' => gitFileList(
            'git diff --cached --name-only -z --diff-filter=D --no-renames -- "*.php"',
            'staged deleted PHP files'
        ),
    ];
}

function rejectPartiallyStagedPhpFiles(array $unstagedPhpFiles, array $stagedPhpFiles): void
{
    $partiallyStagedPhpFiles = array_values(array_intersect($stagedPhpFiles, $unstagedPhpFiles));

    if ($partiallyStagedPhpFiles === []) {
        return;
    }

    fwrite(
        STDERR,
        "Unable to validate partially staged PHP files. Stage their final contents before running Pint:\n"
    );
    foreach ($partiallyStagedPhpFiles as $file) {
        fwrite(STDERR, " - {$file}\n");
    }

    exit(1);
}

function rejectUnstagedPhpDeletions(array $unstagedDeletedPhpFiles): void
{
    if ($unstagedDeletedPhpFiles === []) {
        return;
    }

    fwrite(
        STDERR,
        "Unable to validate unstaged PHP deletions. Stage each deletion or restore the file before running Pint:\n"
    );
    foreach ($unstagedDeletedPhpFiles as $file) {
        fwrite(STDERR, " - {$file}\n");
    }

    exit(1);
}

function diffPhpFiles(string $baseBranch, string $mode, bool $deletedOnly = false): array
{
    $separator = match ($mode) {
        'direct' => '..',
        'merge-base' => '...',
        default => null,
    };
    if ($separator === null) {
        fwrite(STDERR, "Unsupported Pint diff mode.\n");
        exit(1);
    }

    $diffFilter = $deletedOnly ? ' --diff-filter=D --no-renames' : '';
    $description = $deletedOnly ? 'deleted PHP files' : 'PHP files';

    return gitFileList(
        'git diff --name-only -z'.$diffFilter.' '.escapeshellarg($baseBranch.$separator.'HEAD').' -- "*.php"',
        "{$description} changed from {$baseBranch} using {$mode} mode"
    );
}

function runPintOnFiles(string $pintProxy, array $files, array $deletedPhpFiles): int
{
    $projectRoot = dirname(__DIR__);
    $unreadablePhpFiles = [];
    $deletedPhpFileLookup = array_fill_keys($deletedPhpFiles, true);
    $files = array_values(array_filter($files, static function (string $file) use ($projectRoot, $deletedPhpFileLookup, &$unreadablePhpFiles): bool {
        $absolutePath = $projectRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);

        if (! file_exists($absolutePath) && ! is_link($absolutePath)) {
            if (isset($deletedPhpFileLookup[$file])) {
                return false;
            }

            $unreadablePhpFiles[] = $file;

            return false;
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            $unreadablePhpFiles[] = $file;

            return false;
        }

        return true;
    }));

    if ($unreadablePhpFiles !== []) {
        fwrite(STDERR, "Unable to read PHP files selected for Pint:\n");
        foreach ($unreadablePhpFiles as $file) {
            fwrite(STDERR, " - {$file}\n");
        }

        return 1;
    }

    if ($files === []) {
        fwrite(STDOUT, "No PHP files require Pint inspection.\n");

        return 0;
    }

    foreach (array_chunk($files, 40) as $chunk) {
        $process = proc_open(
            [PHP_BINARY, $pintProxy, '--test', '--', ...$chunk],
            [
                1 => STDOUT,
                2 => STDERR,
            ],
            $pipes,
            $projectRoot
        );

        if (! is_resource($process)) {
            fwrite(STDERR, "Unable to start Pint.\n");

            return 1;
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            return $exitCode;
        }
    }

    return 0;
}

function verifiedBaseReference(string $candidate, bool $required = false): ?string
{
    $code = runCommand(
        'git rev-parse --verify '.escapeshellarg($candidate.'^{commit}'),
        $output
    );

    if ($code === 0) {
        return $candidate;
    }

    if ($required) {
        fwrite(STDERR, "Unable to resolve the required Pint base reference.\n");
        exit(1);
    }

    return null;
}

function firstAvailableBaseBranch(): ?string
{
    $explicitBaseRef = getenv('PINT_BASE_REF');
    if (is_string($explicitBaseRef) && trim($explicitBaseRef) !== '') {
        return verifiedBaseReference(trim($explicitBaseRef), true);
    }

    $candidates = [];
    $githubBaseRef = getenv('GITHUB_BASE_REF');
    if (is_string($githubBaseRef) && $githubBaseRef !== '') {
        $candidates[] = 'origin/'.trim($githubBaseRef);
        $candidates[] = trim($githubBaseRef);
    }

    $candidates = array_merge($candidates, [
        'origin/develop',
        'develop',
    ]);

    foreach (array_unique($candidates) as $candidate) {
        $verifiedReference = verifiedBaseReference($candidate);
        if ($verifiedReference !== null) {
            return $verifiedReference;
        }
    }

    return null;
}

function pintDiffMode(): string
{
    $mode = getenv('PINT_DIFF_MODE');
    if (! is_string($mode) || trim($mode) === '') {
        return 'merge-base';
    }

    $mode = trim($mode);
    if (! in_array($mode, ['direct', 'merge-base'], true)) {
        fwrite(STDERR, "PINT_DIFF_MODE must be direct or merge-base.\n");
        exit(1);
    }

    return $mode;
}

$phpFileChanges = phpFileChanges();
rejectPartiallyStagedPhpFiles($phpFileChanges['unstaged'], $phpFileChanges['staged']);
rejectUnstagedPhpDeletions($phpFileChanges['unstaged_deleted']);
$dirtyPhpFiles = array_values(array_unique(array_merge(
    $phpFileChanges['unstaged'],
    $phpFileChanges['staged'],
    $phpFileChanges['untracked'],
)));
$baseBranch = firstAvailableBaseBranch();
$diffMode = pintDiffMode();

if ($baseBranch !== null) {
    $committedPhpFiles = diffPhpFiles($baseBranch, $diffMode);
    $committedDeletedPhpFiles = diffPhpFiles($baseBranch, $diffMode, true);
} else {
    fwrite(STDERR, "Unable to determine the develop base branch for Pint.\n");
    exit(1);
}

$phpFiles = array_values(array_unique(array_merge($dirtyPhpFiles, $committedPhpFiles)));
$deletedPhpFiles = array_values(array_unique(array_merge(
    $phpFileChanges['staged_deleted'],
    $committedDeletedPhpFiles,
)));
fwrite(
    STDOUT,
    sprintf(
        "Pint base: %s (%s); %d PHP file(s) selected (%d dirty, %d committed).\n",
        $baseBranch,
        $diffMode,
        count($phpFiles),
        count($dirtyPhpFiles),
        count($committedPhpFiles),
    )
);

$exitCode = runPintOnFiles($pintProxy, $phpFiles, $deletedPhpFiles);
exit($exitCode);
