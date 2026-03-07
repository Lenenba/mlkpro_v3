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

$dirtyCode = runCommand('git status --porcelain -- "*.php"', $dirtyOutput);
$hasDirtyPhpFiles = $dirtyCode === 0 && count($dirtyOutput) > 0;

if ($hasDirtyPhpFiles) {
    $command = escapeshellarg($pintBinary).' --test --dirty';
    passthru($command, $exitCode);
    exit($exitCode);
}

$baseBranch = firstAvailableBaseBranch();

if ($baseBranch !== null) {
    $command = escapeshellarg($pintBinary).' --test --diff='.escapeshellarg($baseBranch);
    passthru($command, $exitCode);
    exit($exitCode);
}

$command = escapeshellarg($pintBinary).' --test --dirty';
passthru($command, $exitCode);

if ($exitCode === 0) {
    exit(0);
}

fwrite(STDERR, "Unable to determine a base branch for Pint.\n");
exit($exitCode);
