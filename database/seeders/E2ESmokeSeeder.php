<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class E2ESmokeSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();

        $serviceOwner = User::factory()->create([
            'name' => 'E2E Service Owner',
            'email' => 'e2e.service.owner@example.test',
            'company_name' => 'E2E Service Company',
            'company_slug' => 'e2e-service-company',
            'company_type' => 'services',
            'company_sector' => 'construction',
            'is_suspended' => false,
        ]);

        $serviceCustomer = Customer::create([
            'user_id' => $serviceOwner->id,
            'first_name' => 'Service',
            'last_name' => 'Customer',
            'company_name' => 'E2E Service Customer',
            'email' => 'e2e.service.customer@example.test',
            'phone' => '+15145550001',
            'is_active' => true,
        ]);

        $lead = LeadRequest::create([
            'user_id' => $serviceOwner->id,
            'customer_id' => $serviceCustomer->id,
            'status' => LeadRequest::STATUS_QUOTE_SENT,
            'title' => 'E2E Renovation Lead',
            'service_type' => 'Renovation',
        ]);

        $quote = Quote::create([
            'user_id' => $serviceOwner->id,
            'customer_id' => $serviceCustomer->id,
            'request_id' => $lead->id,
            'job_title' => 'E2E Renovation Quote',
            'status' => 'sent',
            'subtotal' => 1250,
            'total' => 1250,
            'initial_deposit' => 0,
        ]);

        $productOwner = User::factory()->create([
            'name' => 'E2E Product Owner',
            'email' => 'e2e.product.owner@example.test',
            'company_name' => 'E2E Product Company',
            'company_slug' => 'e2e-product-company',
            'company_type' => 'products',
            'company_sector' => 'retail',
            'is_suspended' => false,
        ]);

        $category = ProductCategory::create([
            'name' => 'E2E Category',
            'user_id' => $productOwner->id,
            'created_by_user_id' => $productOwner->id,
        ]);

        $stockAlertProduct = Product::create([
            'user_id' => $productOwner->id,
            'category_id' => $category->id,
            'name' => 'E2E Low Stock Product',
            'description' => 'Low stock product for dashboard smoke',
            'price' => 19.99,
            'stock' => 1,
            'minimum_stock' => 2,
            'tax_rate' => 0,
            'item_type' => Product::ITEM_TYPE_PRODUCT,
            'is_active' => true,
            'sku' => 'E2E-LOW-STOCK',
        ]);

        $publicStoreProduct = Product::create([
            'user_id' => $productOwner->id,
            'category_id' => $category->id,
            'name' => 'E2E Public Store Product',
            'description' => 'Public storefront smoke product',
            'price' => 29.99,
            'stock' => 8,
            'minimum_stock' => 1,
            'tax_rate' => 0,
            'item_type' => Product::ITEM_TYPE_PRODUCT,
            'is_active' => true,
            'sku' => 'E2E-PUBLIC-STORE',
        ]);

        $productCustomer = Customer::create([
            'user_id' => $productOwner->id,
            'first_name' => 'Product',
            'last_name' => 'Customer',
            'company_name' => 'E2E Product Customer',
            'email' => 'e2e.product.customer@example.test',
            'phone' => '+15145550002',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'user_id' => $productOwner->id,
            'created_by_user_id' => $productOwner->id,
            'customer_id' => $productCustomer->id,
            'status' => Sale::STATUS_PENDING,
            'subtotal' => 39.98,
            'tax_total' => 0,
            'discount_rate' => 0,
            'discount_total' => 0,
            'total' => 39.98,
        ]);

        $sale->items()->create([
            'product_id' => $stockAlertProduct->id,
            'description' => $stockAlertProduct->name,
            'quantity' => 2,
            'price' => 19.99,
            'total' => 39.98,
        ]);

        $fixturePath = storage_path('app/e2e-fixtures.json');
        File::ensureDirectoryExists(dirname($fixturePath));

        File::put($fixturePath, json_encode([
            'serviceOwner' => [
                'name' => $serviceOwner->name,
                'email' => $serviceOwner->email,
                'password' => 'password',
                'dashboardPath' => route('dashboard', absolute: false),
            ],
            'serviceCustomer' => [
                'companyName' => $serviceCustomer->company_name,
                'email' => $serviceCustomer->email,
                'leadTitle' => $lead->title,
                'quoteNumber' => $quote->number,
                'path' => route('customer.show', $serviceCustomer, absolute: false),
            ],
            'productOwner' => [
                'name' => $productOwner->name,
                'email' => $productOwner->email,
                'password' => 'password',
                'dashboardPath' => route('dashboard', absolute: false),
            ],
            'productDashboard' => [
                'lowStockProductName' => $stockAlertProduct->name,
            ],
            'productSales' => [
                'createPath' => route('sales.create', absolute: false),
                'showPath' => route('sales.show', $sale, absolute: false),
                'saleNumber' => $sale->number,
                'customerCompany' => $productCustomer->company_name,
                'productName' => $stockAlertProduct->name,
            ],
            'publicStore' => [
                'path' => route('public.store.show', $productOwner->company_slug, absolute: false),
                'companyName' => $productOwner->company_name,
                'productName' => $publicStoreProduct->name,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function seedRoles(): void
    {
        foreach (['superadmin', 'admin', 'owner', 'employee', 'client'] as $name) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                ['description' => ucfirst($name).' role']
            );
        }
    }
}
