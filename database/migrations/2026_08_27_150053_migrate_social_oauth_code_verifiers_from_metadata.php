<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->canMigrate()) {
            return;
        }

        DB::table('social_account_connections')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($connections): void {
                foreach ($connections as $connection) {
                    $metadata = $this->decodeMetadata($connection->metadata);

                    if (! array_key_exists('oauth_code_verifier', $metadata)) {
                        continue;
                    }

                    $verifier = trim((string) ($metadata['oauth_code_verifier'] ?? ''));
                    unset($metadata['oauth_code_verifier']);

                    $updates = [
                        'metadata' => $this->encodeMetadata($metadata),
                    ];

                    if (trim((string) ($connection->oauth_code_verifier ?? '')) === '' && $verifier !== '') {
                        $updates['oauth_code_verifier'] = Crypt::encryptString($verifier);
                    }

                    DB::table('social_account_connections')
                        ->where('id', $connection->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        if (! $this->canMigrate()) {
            return;
        }

        DB::table('social_account_connections')
            ->whereNotNull('oauth_code_verifier')
            ->orderBy('id')
            ->chunkById(100, function ($connections): void {
                foreach ($connections as $connection) {
                    $metadata = $this->decodeMetadata($connection->metadata);
                    $metadata['oauth_code_verifier'] = Crypt::decryptString(
                        (string) $connection->oauth_code_verifier
                    );

                    DB::table('social_account_connections')
                        ->where('id', $connection->id)
                        ->update([
                            'oauth_code_verifier' => null,
                            'metadata' => $this->encodeMetadata($metadata),
                        ]);
                }
            });
    }

    private function canMigrate(): bool
    {
        return Schema::hasTable('social_account_connections')
            && Schema::hasColumn('social_account_connections', 'oauth_code_verifier');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeMetadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
};
