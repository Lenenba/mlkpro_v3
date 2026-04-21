<?php

namespace App\Queries\Customers;

final class CustomerReadSelects
{
    public static function detailQuoteColumns(): array
    {
        return ['id', 'customer_id', 'property_id', 'job_title', 'number', 'status', 'total', 'created_at'];
    }

    public static function detailWorkColumns(): array
    {
        return ['id', 'customer_id', 'number', 'job_title', 'status', 'start_date', 'created_at'];
    }

    public static function detailRequestColumns(): array
    {
        return ['id', 'customer_id', 'title', 'service_type', 'status', 'next_follow_up_at', 'created_at'];
    }

    public static function detailInvoiceColumns(): array
    {
        return ['id', 'customer_id', 'user_id', 'number', 'status', 'total', 'created_at'];
    }

    public static function detailTaskColumns(): array
    {
        return ['id', 'title', 'status', 'due_date', 'completed_at', 'assigned_team_member_id'];
    }

    public static function detailUpcomingWorkColumns(): array
    {
        return ['id', 'job_title', 'status', 'start_date', 'end_date', 'created_at'];
    }

    public static function detailPaymentColumns(): array
    {
        return ['id', 'invoice_id', 'amount', 'method', 'status', 'reference', 'paid_at', 'created_at'];
    }

    public static function detailActivityColumns(): array
    {
        return ['id', 'user_id', 'action', 'description', 'properties', 'subject_type', 'subject_id', 'created_at'];
    }

    public static function detailLoyaltyLedgerColumns(): array
    {
        return ['id', 'payment_id', 'event', 'points', 'amount', 'processed_at', 'created_at'];
    }

    public static function detailSalesColumns(): array
    {
        return ['id', 'number', 'status', 'total', 'created_at'];
    }

    public static function optionCustomerColumns(string $scope): array
    {
        return match ($scope) {
            'audience' => ['id', 'company_name', 'first_name', 'last_name', 'email', 'phone'],
            'request', 'quote' => ['id', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'logo', 'number'],
            default => ['id', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'logo', 'number'],
        };
    }

    public static function optionPropertyColumns(string $scope): array
    {
        return match ($scope) {
            'quote' => ['id', 'customer_id', 'is_default', 'street1', 'city'],
            default => ['id', 'customer_id', 'type', 'is_default', 'street1', 'street2', 'city', 'state', 'zip', 'country'],
        };
    }
}
