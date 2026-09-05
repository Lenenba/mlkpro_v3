<?php

namespace App\Services\Social;

use GdImage;
use RuntimeException;

class SocialVideoCaptionRenderer
{
    public function available(): bool
    {
        return function_exists('imagettftext') && is_readable((string) config('social_video.caption_font'));
    }

    /** @param list<array{start_ms: int, end_ms: int, text: string}> $captions */
    public function timeline(array $captions, string $directory, int $width, int $duration, string $style): string
    {
        if (! $this->available()) {
            throw new RuntimeException('captions_unavailable');
        }
        $this->image('', $directory.'/blank.png', $width, $style);
        $manifest = "ffconcat version 1.0\n";
        $cursor = 0;
        foreach ($captions as $index => $caption) {
            if ($caption['start_ms'] > $cursor) {
                $manifest .= $this->entry('blank.png', $caption['start_ms'] - $cursor);
            }
            $name = 'caption-'.$index.'.png';
            $this->image($caption['text'], $directory.'/'.$name, $width, $style);
            $manifest .= $this->entry($name, $caption['end_ms'] - $caption['start_ms']);
            $cursor = $caption['end_ms'];
        }
        if ($cursor < $duration) {
            $manifest .= $this->entry('blank.png', $duration - $cursor);
        }
        $manifest .= "file 'blank.png'\n";
        $path = $directory.'/captions.ffconcat';
        if (file_put_contents($path, $manifest) === false) {
            throw new RuntimeException('processing_failed');
        }

        return $path;
    }

    private function entry(string $name, int $duration): string
    {
        return "file '{$name}'\nduration ".sprintf('%.3F', $duration / 1000)."\n";
    }

    private function image(string $text, string $path, int $width, string $style): void
    {
        $height = 240;
        $image = imagecreatetruecolor($width, $height);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('processing_failed');
        }
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            if ($text !== '') {
                $font = (string) config('social_video.caption_font');
                $size = $width === 720 ? 26 : 30;
                $lines = $this->wrap($text, $font, $size, (int) ($width * 0.84));
                while (count($lines) * ($size + 12) + 24 > $height && $size > 12) {
                    $size--;
                    $lines = $this->wrap($text, $font, $size, (int) ($width * 0.84));
                }
                $lineHeight = $size + 12;
                $top = (int) (($height - count($lines) * $lineHeight) / 2);
                imagefilledrectangle($image, (int) ($width * 0.05), max(0, $top - 12),
                    (int) ($width * 0.95), min($height - 1, $top + count($lines) * $lineHeight + 12),
                    imagecolorallocatealpha($image, 0, 0, 0, 35));
                imagealphablending($image, true);
                $color = $style === 'yellow' ? imagecolorallocate($image, 255, 225, 70) : imagecolorallocate($image, 255, 255, 255);
                foreach ($lines as $index => $line) {
                    $box = imagettfbbox($size, 0, $font, $line);
                    imagettftext($image, $size, 0, (int) (($width - ($box[2] - $box[0])) / 2),
                        $top + $size + $index * $lineHeight, $color, $font, $line);
                }
            }
            if (! imagepng($image, $path)) {
                throw new RuntimeException('processing_failed');
            }
        } finally {
            imagedestroy($image);
        }
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (mb_str_split(preg_replace('/\s+/u', ' ', trim($text)) ?? '') as $character) {
            $candidate = $line.$character;
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($width < $box[2] - $box[0] && $line !== '') {
                $break = mb_strrpos($line, ' ');
                if ($break !== false && $break > 0) {
                    $lines[] = mb_substr($line, 0, $break);
                    $line = mb_substr($line, $break + 1).$character;
                } else {
                    $lines[] = $line;
                    $line = $character;
                }
            } else {
                $line = $candidate;
            }
        }
        if (trim($line) !== '') {
            $lines[] = trim($line);
        }

        return $lines;
    }
}
