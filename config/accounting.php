<?php

return [
    'system_accounts' => [
        [
            'key' => 'accounts_receivable',
            'code' => '1100',
            'name' => 'Accounts receivable',
            'type' => 'asset_current',
            'description' => 'Open customer balances generated from invoices before collection.',
        ],
        [
            'key' => 'cash_and_bank',
            'code' => '1200',
            'name' => 'Cash and bank clearing',
            'type' => 'asset_current',
            'description' => 'Collected cash, card settlements, and payment-clearing movements.',
        ],
        [
            'key' => 'sales_revenue',
            'code' => '4000',
            'name' => 'Sales revenue',
            'type' => 'income',
            'description' => 'Core revenue from invoices and direct sales.',
        ],
        [
            'key' => 'taxes_collected',
            'code' => '2200',
            'name' => 'Taxes collected',
            'type' => 'liability_current',
            'description' => 'Output taxes collected on invoices and sales.',
        ],
        [
            'key' => 'operating_expenses',
            'code' => '6100',
            'name' => 'Operating expenses',
            'type' => 'expense',
            'description' => 'Approved operating spend from the expenses module.',
        ],
        [
            'key' => 'cost_of_goods_sold',
            'code' => '6200',
            'name' => 'Cost of goods sold',
            'type' => 'expense',
            'description' => 'Direct product and delivery costs mapped from catalog or supplier spend.',
        ],
        [
            'key' => 'employee_reimbursements',
            'code' => '2300',
            'name' => 'Employee reimbursements payable',
            'type' => 'liability_current',
            'description' => 'Amounts owed back to team members for reimbursable expenses.',
        ],
        [
            'key' => 'suspense',
            'code' => '9999',
            'name' => 'Suspense and review',
            'type' => 'equity_temporary',
            'description' => 'Temporary holding account when a trusted mapping is still missing.',
        ],
    ],

    'default_mappings' => [
        [
            'source_domain' => 'invoices',
            'source_key' => 'invoice_issued',
            'description' => 'Debit receivable, credit revenue, and isolate taxes collected.',
            'debit_account_key' => 'accounts_receivable',
            'credit_account_key' => 'sales_revenue',
            'tax_account_key' => 'taxes_collected',
        ],
        [
            'source_domain' => 'payments',
            'source_key' => 'payment_collected',
            'description' => 'Debit cash and bank clearing, credit receivable.',
            'debit_account_key' => 'cash_and_bank',
            'credit_account_key' => 'accounts_receivable',
            'tax_account_key' => null,
        ],
        [
            'source_domain' => 'sales',
            'source_key' => 'sale_completed',
            'description' => 'Recognize direct sale revenue and taxes without invoice detour.',
            'debit_account_key' => 'cash_and_bank',
            'credit_account_key' => 'sales_revenue',
            'tax_account_key' => 'taxes_collected',
        ],
        [
            'source_domain' => 'expenses',
            'source_key' => 'expense_paid',
            'description' => 'Debit expense account and credit cash or reimbursement liability.',
            'debit_account_key' => 'operating_expenses',
            'credit_account_key' => 'cash_and_bank',
            'tax_account_key' => null,
        ],
        [
            'source_domain' => 'expenses',
            'source_key' => 'reimbursable_expense_paid',
            'description' => 'Use reimbursement liability instead of direct cash when team reimbursement is pending.',
            'debit_account_key' => 'operating_expenses',
            'credit_account_key' => 'employee_reimbursements',
            'tax_account_key' => null,
        ],
        [
            'source_domain' => 'expenses',
            'source_key' => 'reimbursable_expense_reimbursed',
            'description' => 'Clear the reimbursement liability when the team member has been paid back.',
            'debit_account_key' => 'employee_reimbursements',
            'credit_account_key' => 'cash_and_bank',
            'tax_account_key' => null,
        ],
    ],

    'phase_zero' => [
        'next_steps' => [
            'Create accounting tables for accounts, mappings, entry batches, and entries.',
            'Generate trusted entry batches from expenses, invoices, payments, and sales.',
            'Expose a first journal screen with source traceability and review status.',
        ],
    ],
];
