<?php

namespace App\Support\BulkActions;

use App\Support\BulkActions\Modules\CustomerBulkActionModule;
use App\Support\BulkActions\Modules\ProductBulkActionModule;
use App\Support\BulkActions\Modules\RequestBulkActionModule;
use InvalidArgumentException;

class BulkActionRegistry
{
    /**
     * @var array<string, BulkActionModule>
     */
    private array $modules;

    public function __construct(
        CustomerBulkActionModule $customer,
        ProductBulkActionModule $product,
        RequestBulkActionModule $request,
    ) {
        $this->modules = [
            $customer->key() => $customer,
            $product->key() => $product,
            $request->key() => $request,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function definitionFor(string $module, array $context = []): array
    {
        if (! array_key_exists($module, $this->modules)) {
            throw new InvalidArgumentException(sprintf('Unknown bulk action module [%s].', $module));
        }

        return $this->modules[$module]->definition($context);
    }
}
