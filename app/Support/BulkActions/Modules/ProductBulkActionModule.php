<?php

namespace App\Support\BulkActions\Modules;

use App\Support\BulkActions\BulkActionModule;

class ProductBulkActionModule implements BulkActionModule
{
    public function key(): string
    {
        return 'product';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function definition(array $context = []): array
    {
        $enabled = (bool) ($context['can_edit'] ?? false);

        return [
            'module' => $this->key(),
            'enabled' => $enabled,
            'endpoint' => route('product.bulk'),
            'method' => 'post',
            'menu_label_key' => 'products.bulk.actions',
            'selection_label_key' => 'products.bulk.selected',
            'actions' => [
                [
                    'key' => 'archive',
                    'kind' => 'submit',
                    'action' => 'archive',
                    'label_key' => 'products.actions.archive',
                    'tone' => 'neutral',
                ],
                [
                    'key' => 'restore',
                    'kind' => 'submit',
                    'action' => 'restore',
                    'label_key' => 'products.actions.restore',
                    'tone' => 'success',
                ],
                [
                    'key' => 'delete',
                    'kind' => 'submit',
                    'action' => 'delete',
                    'label_key' => 'products.actions.delete',
                    'tone' => 'danger',
                    'divider_before' => true,
                    'confirm_key' => 'products.bulk.delete_confirm',
                ],
            ],
        ];
    }
}
