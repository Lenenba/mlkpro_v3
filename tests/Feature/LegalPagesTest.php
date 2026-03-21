<?php

use Inertia\Testing\AssertableInertia as Assert;

test('legal pages expose shared public chrome props', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Terms')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );

    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );

    $this->get(route('refund'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Refund')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );
});

test('welcome page exposes shared footer navigation props', function () {
    $this->get(route('welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('megaMenu.display_location', 'header')
            ->where('footerMenu.display_location', 'footer')
            ->where('footerSection.layout', 'footer')
        );
});
