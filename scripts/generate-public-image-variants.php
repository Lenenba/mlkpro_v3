<?php

declare(strict_types=1);

const VARIANT_WIDTHS = [640, 1280];
const WEBP_QUALITY = 80;
const AVIF_QUALITY = 55;

function expectedVariantHeight(int $sourceWidth, int $sourceHeight, int $targetWidth): int
{
    return max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
}

$sourceDirectory = dirname(__DIR__).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'landing'.DIRECTORY_SEPARATOR.'stock';
$destinationDirectory = $sourceDirectory.DIRECTORY_SEPARATOR.'optimized';
$checkOnly = in_array('--check', $argv, true);

if (! function_exists('imagewebp') || ! function_exists('imageavif')) {
    fwrite(STDERR, "Les encodeurs GD WebP et AVIF sont requis.\n");
    exit(1);
}

$sources = glob($sourceDirectory.DIRECTORY_SEPARATOR.'*.jpg') ?: [];
sort($sources);

if ($sources === []) {
    fwrite(STDERR, "Aucun JPEG stock trouvé dans {$sourceDirectory}.\n");
    exit(1);
}

if (! $checkOnly && ! is_dir($destinationDirectory) && ! mkdir($destinationDirectory, 0755, true) && ! is_dir($destinationDirectory)) {
    fwrite(STDERR, "Impossible de créer {$destinationDirectory}.\n");
    exit(1);
}

$expected = 0;

foreach ($sources as $source) {
    $key = pathinfo($source, PATHINFO_FILENAME);
    $sourceSize = getimagesize($source);

    if ($sourceSize === false) {
        fwrite(STDERR, "Dimensions illisibles : {$source}.\n");
        exit(1);
    }

    [$sourceWidth, $sourceHeight] = $sourceSize;

    foreach (VARIANT_WIDTHS as $targetWidth) {
        $targetHeight = expectedVariantHeight($sourceWidth, $sourceHeight, $targetWidth);

        foreach (['avif', 'webp'] as $format) {
            $expected++;
            $target = $destinationDirectory.DIRECTORY_SEPARATOR."{$key}-{$targetWidth}w.{$format}";

            if ($checkOnly) {
                if (! is_file($target) || filesize($target) === 0) {
                    fwrite(STDERR, "Variante absente : {$target}.\n");
                    exit(1);
                }

                $variantSize = getimagesize($target);

                if ($variantSize === false || $variantSize[0] !== $targetWidth || $variantSize[1] !== $targetHeight) {
                    fwrite(STDERR, "Variante invalide : {$target}.\n");
                    exit(1);
                }

                continue;
            }
        }
    }

    if ($checkOnly) {
        continue;
    }

    $image = imagecreatefromjpeg($source);
    if ($image === false) {
        fwrite(STDERR, "JPEG illisible : {$source}.\n");
        exit(1);
    }

    foreach (VARIANT_WIDTHS as $targetWidth) {
        $targetHeight = expectedVariantHeight($sourceWidth, $sourceHeight, $targetWidth);
        $variant = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($variant === false) {
            imagedestroy($image);
            fwrite(STDERR, "Allocation impossible : {$source}.\n");
            exit(1);
        }

        imagecopyresampled($variant, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $avifTarget = $destinationDirectory.DIRECTORY_SEPARATOR."{$key}-{$targetWidth}w.avif";
        $webpTarget = $destinationDirectory.DIRECTORY_SEPARATOR."{$key}-{$targetWidth}w.webp";

        if (! imageavif($variant, $avifTarget, AVIF_QUALITY) || ! imagewebp($variant, $webpTarget, WEBP_QUALITY)) {
            imagedestroy($variant);
            imagedestroy($image);
            fwrite(STDERR, "Encodage impossible : {$source}.\n");
            exit(1);
        }

        imagedestroy($variant);
    }

    imagedestroy($image);
    fwrite(STDOUT, "Variantes générées : {$key}\n");
}

fwrite(STDOUT, $checkOnly ? "{$expected} variantes vérifiées.\n" : "{$expected} variantes générées.\n");
