<?php

use App\Models\Product;
use App\Modules\AiAssistant\Services\MissingInformationMessageBuilder;

test('asks for the name without requesting other missing details at the same time', function (string $language, string $expected) {
    $reply = app(MissingInformationMessageBuilder::class)->build(
        [],
        ['contact_name', 'contact_phone', 'preferred_date'],
        collect(),
        $language,
    );

    expect($reply)->toBe($expected);
})->with([
    'French' => ['fr', 'Pour préparer la demande, quel est votre nom complet?'],
    'English' => ['en', 'To prepare the request, what is your full name?'],
]);

test('asks for the last name when the first name is already known', function (string $language, string $expected, array $missingFields) {
    $reply = app(MissingInformationMessageBuilder::class)->build(
        ['contact_name' => 'jules'],
        $missingFields,
        collect(),
        $language,
    );

    expect($reply)->toBe($expected);
})->with([
    'French' => ['fr', 'Merci Jules. Quel est votre nom de famille?'],
    'English' => ['en', 'Thanks Jules. What is your last name?'],
])->with([
    'only name missing' => [['contact_name']],
    'other details missing' => [['contact_name', 'contact_phone', 'preferred_date']],
]);

test('does not claim the phone is the only missing detail when the date or address is missing', function (string $language, string $expected, string $otherMissingField) {
    $reply = app(MissingInformationMessageBuilder::class)->build(
        ['contact_name' => 'jules roger'],
        ['contact_phone', $otherMissingField],
        collect(),
        $language,
    );

    expect($reply)->toBe($expected);
})->with([
    'French' => ['fr', 'Merci Jules Roger. Il me manque un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'],
    'English' => ['en', 'Thanks Jules Roger. I need a phone number so the team can confirm the request with you.'],
])->with(['preferred_date', 'service_address']);

test('says only when the phone is the last missing detail', function (string $language, string $expected) {
    $reply = app(MissingInformationMessageBuilder::class)->build(
        ['contact_name' => 'jules roger'],
        ['contact_phone'],
        collect(),
        $language,
    );

    expect($reply)->toBe($expected);
})->with([
    'French' => ['fr', 'Merci Jules Roger. Il me manque seulement un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'],
    'English' => ['en', 'Thanks Jules Roger. I only need a phone number so the team can confirm the request with you.'],
]);

test('acknowledges the retained date when the service is selected later', function (string $language, array $date, string $expected) {
    $reply = app(MissingInformationMessageBuilder::class)->selectedServiceAcknowledgement(
        $date,
        ['service_id' => 5, 'service_name' => 'Brushing cheveux longs', ...$date],
        $language,
    );

    expect($reply)->toBe($expected);
})->with([
    'French date' => ['fr', ['preferred_date' => '2026-09-08'], 'Parfait, vous souhaitez réserver le service Brushing cheveux longs. Je note votre préférence pour le 2026-09-08.'],
    'English date' => ['en', ['preferred_date' => '2026-09-08'], 'Perfect, you would like to book Brushing cheveux longs. I have noted your preferred date: on 2026-09-08.'],
    'French period' => ['fr', ['preferred_date_label' => 'next_week'], 'Parfait, vous souhaitez réserver le service Brushing cheveux longs. Je note votre préférence pour la semaine prochaine.'],
    'English period' => ['en', ['preferred_date_label' => 'next_week'], 'Perfect, you would like to book Brushing cheveux longs. I have noted your preferred date: next week.'],
    'no date yet' => ['fr', [], 'Parfait, vous souhaitez réserver le service Brushing cheveux longs.'],
]);

test('shows six numbered services and explains how to request the rest of a large catalog', function (string $language, string $expectedSummary) {
    $services = collect([
        new Product(['name' => 'Balayage']),
        new Product(['name' => 'Box braids courtes']),
        new Product(['name' => 'Box braids longues']),
        new Product(['name' => 'Brushing cheveux courts']),
        new Product(['name' => 'Brushing cheveux longs']),
        new Product(['name' => 'Coiffure événementielle']),
        new Product(['name' => 'Coloration complète']),
    ]);

    $reply = app(MissingInformationMessageBuilder::class)->build([], ['service_id'], $services, $language);

    expect($reply)
        ->toContain("1. Balayage\n2. Box braids courtes\n3. Box braids longues\n4. Brushing cheveux courts\n5. Brushing cheveux longs\n6. Coiffure événementielle\n")
        ->toContain($expectedSummary)
        ->not->toContain('Coloration complète');
})->with([
    'French' => ['fr', '7 services sont disponibles au total. Vous pouvez répondre avec un numéro ci-dessus ou saisir le nom de tout autre service.'],
    'English' => ['en', 'There are 7 services in total. You can reply with a number above or type the name of any other service.'],
]);

test('shows every service in a small catalog in the original order', function (string $language, string $expected) {
    $services = collect([
        18 => new Product(['name' => 'Coupe femme']),
        7 => new Product(['name' => 'Coupe homme']),
    ]);

    $reply = app(MissingInformationMessageBuilder::class)->build([], ['service_id'], $services, $language);

    expect($reply)->toBe($expected);
})->with([
    'French' => ['fr', "Quel service souhaitez-vous réserver? Options disponibles:\n1. Coupe femme\n2. Coupe homme"],
    'English' => ['en', "Which service would you like to book? Available options:\n1. Coupe femme\n2. Coupe homme"],
]);
