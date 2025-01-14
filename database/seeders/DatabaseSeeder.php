<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Quote;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Property;
use App\Models\QuoteTax;
use App\Models\QuoteProduct;
use App\Models\ProductCategory;
use App\Models\Tax;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed the roles table
        Role::factory()->count(4)->create();

        // Superadmin user
        $Superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'role_id' => 1,
            'phone_number' => '+1234567890',
        ]);

        // Admin user
        User::factory()->count(2)->create();

        $users = User::all();
        $categories = ProductCategory::factory(5)->create();

        // Create products and associate with categories and users
        $Products = Product::factory(20)
            ->recycle($categories)
            ->recycle($users)
            ->create();
        // Crée 10 clients avec 2 adresses chacun
        $customers = Customer::factory()
            ->count(4)
            ->recycle($users)
            ->has(Property::factory()->count(2)) // 1 adresse physique + 1 adresse de facturation
            ->create();

        // Supposons que $user est l'utilisateur utilisé pour créer les devis
        foreach ($users as $user) {
            $quotes = Quote::factory()
                ->count(2)
                ->state(function () use ($user) {
                    return [
                        'user_id' => $user->id, // Associer les devis à cet utilisateur
                    ];
                })
                ->recycle($user) // Réutiliser l'utilisateur pour chaque devis
                ->recycle(
                    Customer::where('user_id', $user->id) // Filtrer les clients par utilisateur
                        ->get()
                )
                ->recycle(
                    Property::whereHas('customer', function ($query) use ($user) {
                        $query->where('user_id', $user->id); // Réutiliser les propriétés associées à ces clients
                    })->get()
                )
                ->create();
        }

        $tax = Tax::factory()->count(3)->create();

        QuoteProduct::factory()
            ->recycle($quotes) // Réutiliser les devis existants
            ->recycle($Products) // Réutiliser les produits existants
            ->count(3)
            ->create();

        QuoteTax::factory()
            ->count(2)
            ->recycle($quotes)
            ->recycle($tax)
            ->create();
    }
}
