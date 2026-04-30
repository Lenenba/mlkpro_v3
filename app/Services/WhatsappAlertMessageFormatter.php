<?php

namespace App\Services;

class WhatsappAlertMessageFormatter
{
    /**
     * @param  array<int, array{label?: string|null, value?: mixed}>  $details
     */
    public function build(
        string $brandName,
        string $title,
        ?string $intro = null,
        array $details = [],
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): string {
        $lines = [
            $this->bold($brandName ?: 'Malikia Pro'),
            $this->clean($title),
        ];

        if ($intro) {
            $lines[] = '';
            $lines[] = $this->clean($intro);
        }

        $detailLines = $this->detailLines($details);
        if ($detailLines !== []) {
            $lines[] = '';
            array_push($lines, ...$detailLines);
        }

        if ($actionUrl) {
            $lines[] = '';
            $lines[] = $this->clean($actionLabel ?: 'Voir');
            $lines[] = trim($actionUrl);
        }

        return $this->limit(implode("\n", $lines), 1800);
    }

    /**
     * @param  array<int, array{label?: string|null, value?: mixed}>  $details
     * @return array<int, string>
     */
    private function detailLines(array $details): array
    {
        $lines = [];
        foreach ($details as $detail) {
            $label = $this->clean($detail['label'] ?? '');
            $value = $this->clean((string) ($detail['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $lines[] = "{$label}: ".$this->limit($value, 320);
        }

        return $lines;
    }

    private function bold(string $value): string
    {
        return '*'.$this->clean(str_replace('*', '', $value)).'*';
    }

    private function clean(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?: '';
        $value = preg_replace('/\s*\n\s*/', ' ', $value) ?: '';

        return trim($value);
    }

    private function limit(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $length - 3)).'...';
    }
}
