<?php

namespace App\Support\BulkActions\Modules;

use App\Support\BulkActions\BulkActionModule;

class CustomerBulkActionModule implements BulkActionModule
{
    public function key(): string
    {
        return 'customer';
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
            'endpoint' => route('customer.bulk'),
            'method' => 'post',
            'menu_label_key' => 'customers.bulk.title',
            'selection_label_key' => 'customers.labels.selected',
            'actions' => [
                [
                    'key' => 'contact_selected',
                    'kind' => 'client',
                    'client_handler' => 'openBulkContact',
                    'label_key' => 'customers.bulk_contact.action',
                    'tone' => 'info',
                ],
                [
                    'key' => 'portal_enable',
                    'kind' => 'submit',
                    'action' => 'portal_enable',
                    'label_key' => 'customers.bulk.enable_portal',
                    'tone' => 'success',
                    'divider_before' => true,
                ],
                [
                    'key' => 'portal_disable',
                    'kind' => 'submit',
                    'action' => 'portal_disable',
                    'label_key' => 'customers.bulk.disable_portal',
                    'tone' => 'warning',
                ],
                [
                    'key' => 'archive',
                    'kind' => 'submit',
                    'action' => 'archive',
                    'label_key' => 'customers.actions.archive',
                    'tone' => 'neutral',
                ],
                [
                    'key' => 'restore',
                    'kind' => 'submit',
                    'action' => 'restore',
                    'label_key' => 'customers.actions.restore',
                    'tone' => 'success',
                ],
                [
                    'key' => 'delete',
                    'kind' => 'submit',
                    'action' => 'delete',
                    'label_key' => 'customers.actions.delete',
                    'tone' => 'danger',
                    'divider_before' => true,
                    'confirm_key' => 'customers.bulk.delete_confirm',
                ],
            ],
        ];
    }
}
