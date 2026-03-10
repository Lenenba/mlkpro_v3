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

function dirtyPhpFiles(): array
{
    $files = [];

    runCommand('git diff --name-only -- "*.php"', $unstagedOutput);
    $files = array_merge($files, normalizeFileList($unstagedOutput));

    runCommand('git diff --cached --name-only -- "*.php"', $stagedOutput);
    $files = array_merge($files, normalizeFileList($stagedOutput));

    runCommand('git ls-files --others --exclude-standard -- "*.php"', $untrackedOutput);
    $files = array_merge($files, normalizeFileList($untrackedOutput));

    return array_values(array_unique($files));
}

function diffPhpFiles(string $baseBranch): array
{
    $code = runCommand(
        'git diff --name-only '.escapeshellarg($baseBranch).'...HEAD -- "*.php"',
        $output
    );

    if ($code !== 0) {
        return [];
    }

    return normalizeFileList($output);
}

function runPintOnFiles(string $pintBinary, array $files): int
{
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

function firstAvailableBaseBranch(): ?string
{
    $candidates = [];
    $githubBaseRef = getenv('GITHUB_BASE_REF');
    if (is_string($githubBaseRef) && $githubBaseRef !== '') {
        $candidates[] = 'origin/'.$githubBaseRef;
    }

    $candidates = array_merge($candidates, [
        'origin/main',
        'origin/master',
        'main',
        'master',
    ]);

    foreach (array_unique($candidates) as $candidate) {
        $code = runCommand('git rev-parse --verify '.escapeshellarg($candidate), $output);
        if ($code === 0) {
            return $candidate;
        }
    }

    return null;
}

$dirtyPhpFiles = dirtyPhpFiles();
$hasDirtyPhpFiles = count($dirtyPhpFiles) > 0;

if ($hasDirtyPhpFiles) {
    $exitCode = runPintOnFiles($pintBinary, $dirtyPhpFiles);
    exit($exitCode);
}

$baseBranch = firstAvailableBaseBranch();

if ($baseBranch !== null) {
    $exitCode = runPintOnFiles($pintBinary, diffPhpFiles($baseBranch));
    exit($exitCode);
}

$command = escapeshellarg($pintBinary).' --test --dirty';
passthru($command, $exitCode);

if ($exitCode === 0) {
    exit(0);
}

fwrite(STDERR, "Unable to determine a base branch for Pint.\n");
exit($exitCode);
