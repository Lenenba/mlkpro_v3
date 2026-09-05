<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const APPROVALS = 'social_approval_requests';

    private const CONNECTIONS = 'social_account_connections';

    private const POSTS = 'social_posts';

    private const REVISIONS = 'social_post_revisions';

    private const TARGETS = 'social_post_targets';

    public function up(): void
    {
        if (! Schema::hasTable(self::POSTS)) {
            return;
        }

        $this->createRevisionsTable();
        $this->addPostColumns();
        $this->addApprovalColumns();
        $this->addTargetColumns();
        $this->addTransportUniqueness();
    }

    public function down(): void
    {
        $this->dropTransportUniqueness();
        $this->dropTargetColumns();
        $this->dropApprovalColumns();
        $this->dropPostColumns();

        Schema::dropIfExists(self::REVISIONS);
    }

    private function createRevisionsTable(): void
    {
        if (Schema::hasTable(self::REVISIONS)) {
            return;
        }

        Schema::create(self::REVISIONS, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained(self::POSTS)->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('base_content');
            $table->json('source_snapshot')->nullable();
            $table->json('media_snapshot')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('scheduled_timezone', 64);
            $table->dateTime('scheduled_local_time')->nullable();
            $table->char('payload_hash', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('origin', 32);
            $table->string('approval_provenance', 32)->nullable();
            $table->timestamps();

            $table->unique(
                ['social_post_id', 'revision_number'],
                'social_post_revisions_post_number_uq'
            );
            $table->index(['user_id', 'created_at'], 'social_post_revisions_user_created_idx');
            $table->index(['social_post_id', 'approved_at'], 'social_post_revisions_post_approved_idx');
        });
    }

    private function addPostColumns(): void
    {
        $definitions = [
            'editorial_status' => fn (Blueprint $table) => $table->string('editorial_status', 30)->nullable(),
            'delivery_status' => fn (Blueprint $table) => $table->string('delivery_status', 30)->nullable(),
            'sync_status' => fn (Blueprint $table) => $table->string('sync_status', 30)->nullable(),
            'current_editorial_revision' => fn (Blueprint $table) => $table->unsignedInteger('current_editorial_revision')->nullable(),
            'scheduled_timezone' => fn (Blueprint $table) => $table->string('scheduled_timezone', 64)->nullable(),
            'scheduled_local_time' => fn (Blueprint $table) => $table->dateTime('scheduled_local_time')->nullable(),
            'payload_hash' => fn (Blueprint $table) => $table->char('payload_hash', 64)->nullable(),
            'delivery_aggregated_at' => fn (Blueprint $table) => $table->timestamp('delivery_aggregated_at')->nullable(),
            'editorial_status_source' => fn (Blueprint $table) => $table->string('editorial_status_source', 32)->nullable(),
            'delivery_status_source' => fn (Blueprint $table) => $table->string('delivery_status_source', 32)->nullable(),
            'sync_status_source' => fn (Blueprint $table) => $table->string('sync_status_source', 32)->nullable(),
        ];

        foreach ($definitions as $column => $definition) {
            $this->addColumnIfMissing(self::POSTS, $column, $definition);
        }

        $this->addNullableRevisionForeignId(self::POSTS, 'approved_revision_id');

        if (! Schema::hasIndex(self::POSTS, 'social_posts_user_editorial_idx')) {
            Schema::table(self::POSTS, function (Blueprint $table): void {
                $table->index(['user_id', 'editorial_status'], 'social_posts_user_editorial_idx');
            });
        }

        if (! Schema::hasIndex(self::POSTS, 'social_posts_user_delivery_idx')) {
            Schema::table(self::POSTS, function (Blueprint $table): void {
                $table->index(['user_id', 'delivery_status'], 'social_posts_user_delivery_idx');
            });
        }
    }

    private function addApprovalColumns(): void
    {
        if (! Schema::hasTable(self::APPROVALS)) {
            return;
        }

        $this->addNullableRevisionForeignId(self::APPROVALS, 'social_post_revision_id');
    }

    private function addTargetColumns(): void
    {
        if (! Schema::hasTable(self::TARGETS)) {
            return;
        }

        $definitions = [
            'current_editorial_revision' => fn (Blueprint $table) => $table->unsignedInteger('current_editorial_revision')->nullable(),
            'delivery_status' => fn (Blueprint $table) => $table->string('delivery_status', 30)->nullable(),
            'sync_status' => fn (Blueprint $table) => $table->string('sync_status', 30)->nullable(),
            'payload_hash' => fn (Blueprint $table) => $table->char('payload_hash', 64)->nullable(),
        ];

        foreach ($definitions as $column => $definition) {
            $this->addColumnIfMissing(self::TARGETS, $column, $definition);
        }

        $this->addNullableRevisionForeignId(self::TARGETS, 'current_revision_id');
        $this->addNullableRevisionForeignId(self::TARGETS, 'last_submitted_revision_id');

        if (! Schema::hasIndex(self::TARGETS, 'social_post_targets_post_delivery_idx')) {
            Schema::table(self::TARGETS, function (Blueprint $table): void {
                $table->index(
                    ['social_post_id', 'delivery_status'],
                    'social_post_targets_post_delivery_idx'
                );
            });
        }
    }

    private function addTransportUniqueness(): void
    {
        if (Schema::hasTable(self::CONNECTIONS)
            && Schema::hasColumn(self::CONNECTIONS, 'logical_destination_key')
            && ! Schema::hasIndex(self::CONNECTIONS, 'sac_transport_destination_uq')) {
            Schema::table(self::CONNECTIONS, function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'delivery_provider', 'transport_generation', 'logical_destination_key'],
                    'sac_transport_destination_uq'
                );
            });
        }

        if (Schema::hasTable(self::TARGETS)
            && Schema::hasColumn(self::TARGETS, 'logical_destination_key')
            && ! Schema::hasIndex(self::TARGETS, 'spt_post_destination_uq')) {
            Schema::table(self::TARGETS, function (Blueprint $table): void {
                $table->unique(
                    ['social_post_id', 'logical_destination_key'],
                    'spt_post_destination_uq'
                );
            });
        }
    }

    /**
     * @param  callable(Blueprint): mixed  $definition
     */
    private function addColumnIfMissing(string $tableName, string $column, callable $definition): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }

    private function addNullableRevisionForeignId(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->unsignedBigInteger($column)->nullable();
            });
        }

        $hasForeignKey = collect(Schema::getForeignKeys($tableName))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);

        if ($hasForeignKey) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->foreign($column)
                ->references('id')
                ->on(self::REVISIONS)
                ->nullOnDelete();
        });
    }

    private function dropTransportUniqueness(): void
    {
        if (Schema::hasTable(self::TARGETS)
            && Schema::hasIndex(self::TARGETS, 'spt_post_destination_uq')) {
            Schema::table(self::TARGETS, function (Blueprint $table): void {
                $table->dropUnique('spt_post_destination_uq');
            });
        }

        if (Schema::hasTable(self::CONNECTIONS)
            && Schema::hasIndex(self::CONNECTIONS, 'sac_transport_destination_uq')) {
            Schema::table(self::CONNECTIONS, function (Blueprint $table): void {
                $table->dropUnique('sac_transport_destination_uq');
            });
        }
    }

    private function dropTargetColumns(): void
    {
        if (! Schema::hasTable(self::TARGETS)) {
            return;
        }

        if (Schema::hasIndex(self::TARGETS, 'social_post_targets_post_delivery_idx')) {
            Schema::table(self::TARGETS, function (Blueprint $table): void {
                $table->dropIndex('social_post_targets_post_delivery_idx');
            });
        }

        $this->dropConstrainedColumnIfPresent(self::TARGETS, 'last_submitted_revision_id');
        $this->dropConstrainedColumnIfPresent(self::TARGETS, 'current_revision_id');
        $this->dropColumnsIfPresent(self::TARGETS, [
            'current_editorial_revision',
            'delivery_status',
            'sync_status',
            'payload_hash',
        ]);
    }

    private function dropApprovalColumns(): void
    {
        if (Schema::hasTable(self::APPROVALS)) {
            $this->dropConstrainedColumnIfPresent(self::APPROVALS, 'social_post_revision_id');
        }
    }

    private function dropPostColumns(): void
    {
        if (! Schema::hasTable(self::POSTS)) {
            return;
        }

        if (Schema::hasIndex(self::POSTS, 'social_posts_user_delivery_idx')) {
            Schema::table(self::POSTS, function (Blueprint $table): void {
                $table->dropIndex('social_posts_user_delivery_idx');
            });
        }

        if (Schema::hasIndex(self::POSTS, 'social_posts_user_editorial_idx')) {
            Schema::table(self::POSTS, function (Blueprint $table): void {
                $table->dropIndex('social_posts_user_editorial_idx');
            });
        }

        $this->dropConstrainedColumnIfPresent(self::POSTS, 'approved_revision_id');
        $this->dropColumnsIfPresent(self::POSTS, [
            'editorial_status',
            'delivery_status',
            'sync_status',
            'current_editorial_revision',
            'scheduled_timezone',
            'scheduled_local_time',
            'payload_hash',
            'delivery_aggregated_at',
            'editorial_status_source',
            'delivery_status_source',
            'sync_status_source',
        ]);
    }

    private function dropConstrainedColumnIfPresent(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        $hasForeignKey = collect(Schema::getForeignKeys($tableName))
            ->contains(fn (array $foreignKey): bool => array_map(
                'strtolower',
                (array) $foreignKey['columns']
            ) === [strtolower($column)]);

        if ($hasForeignKey) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        }

        if (Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column)
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
