<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pintBinary = $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pint';

if (! file_exists($pintBinary) && file_exists($pintBinary.'.bat')) {
    $pintBinary .= '.bat';
}

if (! file_exists($pintBinary)) {
    fwrite(STDERR, "Unable to locate Pint binary.\n");
    exit(1);
}

function runCommand(string $command, ?array &$output = null): int
{
    $output = [];
    exec($command.' 2>&1', $output, $code);

    return $code;
}

function normalizeFileList(array $lines): array
{
    return array_values(array_unique(array_filter(array_map(static function (string $line): string {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, ' -> ')) {
            $parts = explode(' -> ', $trimmed);
            $trimmed = (string) end($parts);
        }

        if (preg_match('/^[A-Z?]{1,2}\s+(.+)$/', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }, $lines), static fn (string $path): bool => $path !== '')));
}

function gitFileList(string $command, string $description): array
{
    $code = runCommand($command, $output);

    if ($code !== 0) {
        fwrite(STDERR, "Unable to determine {$description}.\n");
        exit(1);
    }

    return normalizeFileList($output);
}

function dirtyPhpFiles(): array
{
    $files = array_merge(
        gitFileList('git diff --name-only -- "*.php"', 'unstaged PHP files'),
        gitFileList('git diff --cached --name-only -- "*.php"', 'staged PHP files'),
        gitFileList('git ls-files --others --exclude-standard -- "*.php"', 'untracked PHP files'),
    );

    return array_values(array_unique($files));
}

function diffPhpFiles(string $baseBranch): array
{
    $code = runCommand(
        'git diff --name-only '.escapeshellarg($baseBranch).'...HEAD -- "*.php"',
        $output
    );

    if ($code !== 0) {
        fwrite(STDERR, "Unable to determine PHP files changed from {$baseBranch}.\n");
        exit(1);
    }

    return normalizeFileList($output);
}

function runPintOnFiles(string $pintBinary, array $files): int
{
    $projectRoot = dirname(__DIR__);
    $files = array_values(array_filter($files, static function (string $file) use ($projectRoot): bool {
        $absolutePath = $projectRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

        return is_file($absolutePath) && is_readable($absolutePath);
    }));

    if ($files === []) {
        fwrite(STDOUT, "No PHP files require Pint inspection.\n");

        return 0;
    }

    foreach (array_chunk($files, 40) as $chunk) {
        $arguments = array_map(static fn (string $file): string => escapeshellarg($file), $chunk);
        $command = escapeshellarg($pintBinary).' --test '.implode(' ', $arguments);
        passthru($command, $exitCode);

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

$dirtyPhpFiles = dirtyPhpFiles();
$baseBranch = firstAvailableBaseBranch();

if ($baseBranch !== null) {
    $committedPhpFiles = diffPhpFiles($baseBranch);
} else {
    fwrite(STDERR, "Unable to determine the develop base branch for Pint.\n");
    exit(1);
}

$phpFiles = array_values(array_unique(array_merge($dirtyPhpFiles, $committedPhpFiles)));
fwrite(
    STDOUT,
    sprintf(
        "Pint base: %s; %d PHP file(s) selected (%d dirty, %d committed).\n",
        $baseBranch,
        count($phpFiles),
        count($dirtyPhpFiles),
        count($committedPhpFiles),
    )
);

$exitCode = runPintOnFiles($pintBinary, $phpFiles);
exit($exitCode);
