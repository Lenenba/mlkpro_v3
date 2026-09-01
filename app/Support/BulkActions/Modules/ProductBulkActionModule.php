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
        $canEdit = (bool) ($context['can_edit'] ?? false);
        $canDelete = (bool) ($context['can_delete'] ?? false);
        $canRequestSupplier = (bool) ($context['can_request_supplier'] ?? false);
        $showCreateOrder = ($context['company_type'] ?? null) === 'products'
            && (bool) ($context['can_create_order'] ?? false);
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

        if ($canRequestSupplier) {
            $actions[] = [
                'key' => 'supplier-request',
                'kind' => 'submit',
                'action' => 'supplier_request',
                'label_key' => 'products.bulk.request_supplier',
                'tone' => 'warning',
                'confirm_key' => 'products.bulk.request_supplier_confirm',
            ];
        }

        if ($canEdit) {
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
        }

        if ($canDelete) {
            $actions[] = [
                'key' => 'delete',
                'kind' => 'submit',
                'action' => 'delete',
                'label_key' => 'products.actions.delete',
                'tone' => 'danger',
                'divider_before' => true,
                'confirm_key' => 'products.bulk.delete_confirm',
            ];
        }

        return [
            'module' => $this->key(),
            'enabled' => $actions !== [],
            'endpoint' => route('product.bulk'),
            'method' => 'post',
            'menu_label_key' => 'products.bulk.actions',
            'selection_label_key' => 'products.bulk.selected',
            'actions' => $actions,
        ];
    }
}
