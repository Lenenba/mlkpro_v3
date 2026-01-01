<?php

return [
    'sector_labels' => [
        'menuiserie' => 'Carpentry',
        'plomberie' => 'Plumbing',
        'electricite' => 'Electrical',
        'peinture' => 'Painting',
        'toiture' => 'Roofing',
        'renovation' => 'Renovation',
        'paysagisme' => 'Landscaping',
        'climatisation' => 'HVAC',
        'nettoyage' => 'Cleaning',
        'autre' => 'General',
        'general' => 'General',
    ],
    'defaults' => [
        'quote_messages' => "Hello,\nThank you for your {sector} request. Here is your quote.\nThis quote is valid for 30 days.",
        'quote_notes' => "Scope: Work as listed in line items.\nPayment: 30% deposit to start, balance due on completion.\nSchedule: Start after approval and materials availability.",
        'invoice_note' => "Thank you for your business.\nPayment is due within 14 days.",
    ],
    'sector_overrides' => [
        'menuiserie' => [
            'quote_notes' => "Scope: Custom carpentry and installation as listed.\nMaterials: Wood species and finishes confirmed before production.\nPayment: 30% deposit to start, balance due on completion.\nSchedule: Lead time includes fabrication and on-site install.",
        ],
        'plomberie' => [
            'quote_notes' => "Scope: Plumbing work as listed.\nAccess: Clear access to water shutoff and fixtures.\nPayment: 30% deposit to start, balance due on completion.\nSchedule: Service window confirmed after approval.",
        ],
        'electricite' => [
            'quote_notes' => "Scope: Electrical work as listed.\nSafety: Power will be shut off during parts of the work.\nPayment: 30% deposit to start, balance due on completion.\nCompliance: Work follows applicable codes.",
        ],
        'peinture' => [
            'quote_notes' => "Scope: Painting prep and finish as listed.\nPrep: Surface repair and protection included.\nPayment: 30% deposit to start, balance due on completion.\nCure: Drying time required between coats.",
        ],
        'toiture' => [
            'quote_notes' => "Scope: Roofing work as listed.\nWeather: Schedule depends on safe weather conditions.\nPayment: 30% deposit to start, balance due on completion.\nWarranty: Manufacturer warranty applies to materials.",
        ],
        'renovation' => [
            'quote_notes' => "Scope: Renovation work as listed.\nSite: Client provides access and clear work areas.\nPayment: 30% deposit to start, balance due on completion.\nChanges: Any change requests will be quoted separately.",
        ],
        'paysagisme' => [
            'quote_notes' => "Scope: Landscaping services as listed.\nSeason: Schedule may shift based on weather and season.\nPayment: 30% deposit to start, balance due on completion.\nCare: See notes for watering and maintenance tips.",
        ],
        'climatisation' => [
            'quote_notes' => "Scope: HVAC installation or service as listed.\nAccess: Electrical panel and equipment access required.\nPayment: 30% deposit to start, balance due on completion.\nStartup: System test and basic usage walkthrough included.",
        ],
        'nettoyage' => [
            'quote_notes' => "Scope: Cleaning services as listed.\nAccess: Client provides access and clear entry paths.\nPayment: Due on completion unless otherwise agreed.\nSupplies: Standard cleaning supplies included.",
        ],
    ],
    'examples' => [
        [
            'key' => 'standard',
            'label' => '{sector} - Standard',
            'messages' => "Hello,\nPlease review this {sector} quote. We can adjust the scope if needed.",
            'notes' => "Materials and labor are included as listed.\nDeposit required to schedule the work.",
        ],
        [
            'key' => 'detailed',
            'label' => '{sector} - Detailed',
            'messages' => "Hello,\nHere is a detailed {sector} estimate with line items and notes.",
            'notes' => "Scope includes prep, execution, and cleanup.\nPayment terms: 30% deposit, balance due on completion.",
        ],
    ],
];
