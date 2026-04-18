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
        $showCreateOrder = ($context['company_type'] ?? null) === 'products';
        $actions = [];

        if ($showCreateOrder) {
            $actions[] = [
                'key' => 'create-order',
                'kind' => 'navigate',
                'client_action' => 'create_order',
                'label_key' => 'products.bulk.create_order',
                'tone' => 'info',
            ];
        }

        $actions[] = [
            'key' => 'supplier-request',
            'kind' => 'submit',
            'action' => 'supplier_request',
            'label_key' => 'products.bulk.request_supplier',
            'tone' => 'warning',
            'confirm_key' => 'products.bulk.request_supplier_confirm',
        ];
        $actions[] = [
            'key' => 'archive',
            'kind' => 'submit',
            'action' => 'archive',
            'label_key' => 'products.actions.archive',
            'tone' => 'neutral',
            'divider_before' => true,
        ];
        $actions[] = [
            'key' => 'restore',
            'kind' => 'submit',
            'action' => 'restore',
            'label_key' => 'products.actions.restore',
            'tone' => 'success',
        ];
        $actions[] = [
            'key' => 'delete',
            'kind' => 'submit',
            'action' => 'delete',
            'label_key' => 'products.actions.delete',
            'tone' => 'danger',
            'divider_before' => true,
            'confirm_key' => 'products.bulk.delete_confirm',
        ];

        return [
            'module' => $this->key(),
            'enabled' => $enabled,
            'endpoint' => route('product.bulk'),
            'method' => 'post',
            'menu_label_key' => 'products.bulk.actions',
            'selection_label_key' => 'products.bulk.selected',
            'actions' => $actions,
        ];
    }
}
