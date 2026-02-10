<?php

return [
    'max_percent' => (float) env('TIP_MAX_PERCENT', 30),
    'max_fixed_amount' => (float) env('TIP_MAX_FIXED_AMOUNT', 200),
    'default_percent' => (float) env('TIP_DEFAULT_PERCENT', 10),
    'quick_percents' => [5, 10, 15, 20],
    'quick_fixed_amounts' => [2, 5, 10],
    'allocation_strategy' => env('TIP_ALLOCATION_STRATEGY', 'primary'),
    'partial_refund_rule' => env('TIP_PARTIAL_REFUND_RULE', 'prorata'),
];
