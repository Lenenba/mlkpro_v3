<?php

return [
    'default_template' => 'modern',

    'templates' => [
        'modern' => [
            'view' => 'pdf.invoice',
            'label_key' => 'settings.company.finance.templates.options.modern.title',
            'description_key' => 'settings.company.finance.templates.options.modern.description',
        ],
        'clean_professional' => [
            'view' => 'pdf.invoice-clean',
            'label_key' => 'settings.company.finance.templates.options.clean_professional.title',
            'description_key' => 'settings.company.finance.templates.options.clean_professional.description',
        ],
        'minimal_corporate' => [
            'view' => 'pdf.invoice-minimal',
            'label_key' => 'settings.company.finance.templates.options.minimal_corporate.title',
            'description_key' => 'settings.company.finance.templates.options.minimal_corporate.description',
        ],
    ],
];
