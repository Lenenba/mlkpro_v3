<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class CompanyPublicSlugService
{
    public function ensureFor(User $account): string
    {
        $current = trim((string) $account->company_slug);
        if ($current !== '') {
            return $current;
        }

        $slug = $this->uniqueSlug($this->sourceName($account), (int) $account->id);

        $account->forceFill([
            'company_slug' => $slug,
        ])->save();
        $account->company_slug = $slug;

        return $slug;
    }

    public function uniqueSlug(string $value, int $ignoreUserId): string
    {
        $base = Str::limit(Str::slug($value), 120, '');
        if ($base === '') {
            $base = 'company-'.$ignoreUserId;
        }

        $slug = $base;
        $counter = 2;
        while (
            User::query()
                ->where('company_slug', $slug)
                ->where('id', '!=', $ignoreUserId)
                ->exists()
        ) {
            $suffix = '-'.$counter;
            $slug = Str::limit($base, 120 - strlen($suffix), '').$suffix;
            $counter++;
        }

        return $slug;
    }

    private function sourceName(User $account): string
    {
        return (string) ($account->company_name ?: $account->name ?: 'company-'.$account->id);
    }
}
