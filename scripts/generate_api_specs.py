import json
import os
import re
import subprocess
from collections import defaultdict
from pathlib import Path


def schema_object(*, required: list[str] | None = None, properties: dict | None = None) -> dict:
    schema = {
        "type": "object",
        "additionalProperties": True,
    }

    if properties:
        schema["properties"] = properties

    if required:
        schema["required"] = required

    return schema


def schema_array(items: dict) -> dict:
    return {
        "type": "array",
        "items": items,
    }


def json_response(description: str, *, required: list[str] | None = None, example: dict | None = None) -> dict:
    media = {
        "schema": schema_object(required=required),
    }
    if example is not None:
        media["example"] = example

    return {
        "description": description,
        "content": {
            "application/json": media,
        },
    }


def request_body(description: str, properties: dict, *, required: list[str] | None = None, example: dict | None = None) -> dict:
    media = {
        "schema": schema_object(required=required, properties=properties),
    }
    if example is not None:
        media["example"] = example

    return {
        "required": True,
        "description": description,
        "content": {
            "application/json": media,
        },
    }


def query_parameter(name: str, schema: dict, description: str, *, example=None) -> dict:
    parameter = {
        "name": name,
        "in": "query",
        "required": False,
        "schema": schema,
        "description": description,
    }
    if example is not None:
        parameter["example"] = example

    return parameter


def pretty_json(value: dict) -> str:
    return json.dumps(value, indent=2)


def deep_merge(base: dict, override: dict) -> dict:
    merged = dict(base)

    for key, value in override.items():
        current = merged.get(key)
        if isinstance(current, dict) and isinstance(value, dict):
            merged[key] = deep_merge(current, value)
        else:
            merged[key] = value

    return merged


VALIDATION_ERROR_EXAMPLE = {
    "message": "The given data was invalid.",
    "errors": {
        "plan_key": ["Please choose a valid subscription plan."],
    },
}


OPENAPI_OPERATION_OVERRIDES: dict[tuple[str, str], dict] = {
    ("/api/v1/auth/me", "get"): {
        "summary": "Get authenticated session bootstrap",
        "description": "Returns the lean mobile bootstrap contract for the authenticated user. This route is intended for identity, owner context, broad feature visibility, and platform or team membership metadata. It is not the full billing summary contract.",
        "responses": {
            "200": json_response(
                "Authenticated session bootstrap returned successfully.",
                required=["user", "meta"],
                example={
                    "user": {
                        "id": 42,
                        "name": "Jules Roger",
                        "email": "owner@example.com",
                        "company_name": "Acme Studio",
                    },
                    "meta": {
                        "role_name": "owner",
                        "owner_id": 42,
                        "is_owner": True,
                        "is_client": False,
                        "is_superadmin": False,
                        "is_platform_admin": False,
                        "company": {
                            "name": "Acme Studio",
                            "type": "services",
                            "onboarded": True,
                            "logo_url": "https://example.com/logo.png",
                        },
                        "features": {
                            "assistant": True,
                            "reservations": True,
                        },
                        "platform": None,
                        "team": None,
                    },
                },
            ),
            "401": json_response(
                "Authentication is required to restore the mobile session.",
                required=["message"],
                example={"message": "Unauthenticated."},
            ),
        },
    },
    ("/api/v1/global-search", "get"): {
        "summary": "Search across the current workspace",
        "description": "Returns the grouped global search contract used by mobile to query customers, tasks, quotes, and employee directory results. Queries shorter than two characters intentionally return an empty group list.",
        "parameters": [
            query_parameter("q", {"type": "string"}, "Search query. Queries shorter than two characters return no results.", example="Search"),
        ],
        "responses": {
            "200": json_response(
                "Grouped global search results returned successfully.",
                required=["query", "groups"],
                example={
                    "query": "Search",
                    "groups": [
                        {
                            "type": "customers",
                            "items": [
                                {
                                    "id": 101,
                                    "title": "Search Client",
                                    "subtitle": "search-client@example.test",
                                    "url": "http://localhost/customer/101",
                                }
                            ],
                        },
                        {
                            "type": "tasks",
                            "items": [
                                {
                                    "id": 202,
                                    "title": "Search Task",
                                    "subtitle": "Todo · 2026-04-10",
                                    "url": "http://localhost/tasks/202",
                                }
                            ],
                        },
                        {
                            "type": "quotes",
                            "items": [
                                {
                                    "id": 303,
                                    "title": "Q-0001",
                                    "subtitle": "Search Quote",
                                    "url": "http://localhost/customer/quote/303/show",
                                }
                            ],
                        },
                        {
                            "type": "employees",
                            "items": [
                                {
                                    "id": 42,
                                    "title": "Search Owner",
                                    "subtitle": "search-owner@example.test",
                                    "url": "http://localhost/performance/employees/42",
                                }
                            ],
                        },
                    ],
                },
            ),
            "401": json_response(
                "Authentication is required to access workspace search.",
                required=["message"],
                example={"message": "Unauthenticated."},
            ),
            "403": json_response(
                "Only internal users can access workspace search.",
                required=["message"],
                example={"message": "This action is unauthorized."},
            ),
        },
    },
    ("/api/v1/pipeline", "get"): {
        "summary": "Get canonical pipeline timeline data",
        "description": "Returns the canonical mobile pipeline contract for request, quote, job, task, and invoice timeline flows. This is the JSON route mobile should call. The web-only `/pipeline/timeline/{entityType}/{entityId}` route remains an Inertia wrapper and is not the canonical API entry point.",
        "parameters": [
            query_parameter(
                "entityType",
                {
                    "type": "string",
                    "enum": ["request", "quote", "job", "task", "invoice"],
                },
                "Pipeline source entity type.",
                example="task",
            ),
            query_parameter(
                "entityId",
                {"type": "string"},
                "Identifier of the source entity inside the current owner workspace.",
                example="202",
            ),
        ],
        "responses": {
            "200": json_response(
                "Canonical pipeline payload returned successfully.",
                required=["source", "request", "quote", "job", "tasks", "invoice", "billing", "derived"],
                example={
                    "source": {
                        "type": "task",
                        "id": "202",
                    },
                    "request": {
                        "id": 101,
                        "title": "Pipeline Request",
                        "service_type": "Landscaping",
                        "status": "REQ_QUOTE_SENT",
                        "created_at": "2026-04-10T14:00:00Z",
                        "converted_at": None,
                        "customer": {
                            "id": 88,
                            "name": "Pipeline Client",
                            "email": "pipeline-client@example.test",
                            "phone": "+15555550100",
                        },
                    },
                    "quote": {
                        "id": 303,
                        "number": "Q-0001",
                        "status": "accepted",
                        "job_title": "Pipeline Quote",
                        "total": 1200.0,
                        "subtotal": 1000.0,
                        "created_at": "2026-04-10T14:15:00Z",
                        "accepted_at": "2026-04-10T14:20:00Z",
                        "customer": {
                            "id": 88,
                            "name": "Pipeline Client",
                            "email": "pipeline-client@example.test",
                            "phone": "+15555550100",
                        },
                    },
                    "job": {
                        "id": 404,
                        "number": "W-0001",
                        "job_title": "Pipeline Job",
                        "status": "in_progress",
                        "start_date": "2026-04-10",
                        "end_date": "2026-04-11",
                        "total": 1200.0,
                        "subtotal": 1000.0,
                        "customer": {
                            "id": 88,
                            "name": "Pipeline Client",
                            "email": "pipeline-client@example.test",
                            "phone": "+15555550100",
                        },
                    },
                    "tasks": [
                        {
                            "id": 202,
                            "title": "Completed billed task",
                            "status": "done",
                            "due_date": "2026-04-10",
                            "completed_at": "2026-04-10T16:00:00Z",
                            "assignee": "Pipeline Worker",
                            "billable": True,
                            "billing_status": "partial",
                            "invoice_id": 505,
                        },
                        {
                            "id": 203,
                            "title": "Pending unbilled task",
                            "status": "todo",
                            "due_date": "2026-04-11",
                            "completed_at": None,
                            "assignee": None,
                            "billable": True,
                            "billing_status": "unbilled",
                            "invoice_id": None,
                        },
                    ],
                    "invoice": {
                        "id": 505,
                        "number": "I-0001",
                        "status": "partial",
                        "total": 800.0,
                        "amount_paid": 300.0,
                        "balance_due": 500.0,
                        "created_at": "2026-04-10T16:30:00Z",
                        "customer": {
                            "id": 88,
                            "name": "Pipeline Client",
                            "email": "pipeline-client@example.test",
                            "phone": "+15555550100",
                        },
                    },
                    "billing": {
                        "quote_total": 1200.0,
                        "invoice_total": 800.0,
                        "remaining_to_bill": 400.0,
                        "amount_paid": 300.0,
                        "balance_due": 500.0,
                    },
                    "derived": {
                        "completeness": 100,
                        "globalStatus": "partial",
                        "alerts": ["Tasks pending."],
                    },
                },
            ),
            "403": json_response(
                "Only the account owner can inspect the full owner pipeline contract.",
                required=["message"],
                example={"message": "This action is unauthorized."},
            ),
            "409": json_response(
                "Owners must complete onboarding before loading pipeline data.",
                required=["message", "onboarding_required"],
                example={
                    "message": "Onboarding required.",
                    "onboarding_required": True,
                },
            ),
            "422": json_response(
                "The entity type or entity id is invalid.",
                required=["message", "errors"],
                example={
                    "message": "The given data was invalid.",
                    "errors": {
                        "entityType": ["The selected entity type is invalid."],
                    },
                },
            ),
        },
    },
    ("/api/v1/ai/images", "post"): {
        "summary": "Generate an AI image",
        "description": "Generates a store or product image through the same backend AI workflow used by the web app. The contract preserves free daily usage, owner-level assistant credit consumption, refund on failure, storage, and returned public image URL.",
        "requestBody": request_body(
            "AI image generation request payload.",
            {
                "prompt": {"type": "string", "maxLength": 800},
                "context": {
                    "type": "string",
                    "enum": ["store", "product"],
                },
            },
            required=["prompt", "context"],
            example={
                "prompt": "Generate a polished storefront hero image with plants and warm daylight.",
                "context": "store",
            },
        ),
        "responses": {
            "200": json_response(
                "AI image generated successfully.",
                required=["url", "mode", "remaining", "credit_balance"],
                example={
                    "url": "http://localhost/storage/company/ai/42/store-550e8400-e29b-41d4-a716-446655440000.png",
                    "mode": "free",
                    "remaining": 0,
                    "credit_balance": 7,
                },
            ),
            "409": json_response(
                "Owners must complete onboarding before loading internal AI image generation.",
                required=["message", "onboarding_required"],
                example={
                    "message": "Onboarding required.",
                    "onboarding_required": True,
                },
            ),
            "422": json_response(
                "The request is invalid or the AI provider rejected the generation request.",
                required=["message"],
                example={
                    "message": "Limite atteinte. Reessayez dans quelques minutes.",
                },
            ),
            "429": json_response(
                "The free daily image allowance is exhausted and no assistant credits are available.",
                required=["message"],
                example={
                    "message": "Limite quotidienne d'images IA atteinte. Achetez un pack IA pour continuer.",
                },
            ),
            "500": json_response(
                "Image generation failed after credit consumption and the consumed credit was refunded.",
                required=["message"],
                example={
                    "message": "Generation d'image indisponible.",
                },
            ),
        },
    },
    ("/api/v1/settings/security", "get"): {
        "summary": "Get security settings and 2FA state",
        "description": "Returns the normalized security payload used by mobile security and two-factor management screens. The payload includes current 2FA posture, temporary authenticator app setup state when one is pending, and recent security activity.",
        "responses": {
            "200": json_response(
                "Security settings payload returned successfully.",
                required=["two_factor", "rate_limit", "can_view_team", "activity"],
                example={
                    "two_factor": {
                        "required": True,
                        "enabled": True,
                        "method": "email",
                        "has_app": False,
                        "can_configure": True,
                        "app_setup": {
                            "setup_token": "setup_token_123",
                            "secret": "JBSWY3DPEHPK3PXP",
                            "otpauth_url": "otpauth://totp/Malikia%20Pro:owner%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Malikia+Pro&period=30&digits=6",
                            "expires_at": "2026-04-10T14:45:00Z",
                        },
                        "email": "owner@example.com",
                        "phone_hint": "+*******0123",
                        "last_sent_at": "2026-04-10T14:20:00Z",
                        "sms": {
                            "available": True,
                            "has_phone": True,
                            "phone_hint": "+*******0123",
                            "twilio_configured": True,
                            "company_enabled": True,
                        },
                    },
                    "rate_limit": 60,
                    "can_view_team": True,
                    "activity": [
                        {
                            "id": 501,
                            "action": "auth.login",
                            "created_at": "2026-04-10T14:00:00Z",
                            "ip": "127.0.0.1",
                            "user_agent": "MalikiaPro/1.0",
                            "channel": "api",
                            "two_factor": True,
                            "device": "iPhone",
                            "subject": {
                                "id": 42,
                                "name": "Owner User",
                                "email": "owner@example.com",
                                "profile_picture": None,
                                "profile_picture_url": None,
                            },
                            "actor": {
                                "id": 42,
                                "name": "Owner User",
                                "email": "owner@example.com",
                                "profile_picture": None,
                                "profile_picture_url": None,
                            },
                        }
                    ],
                },
            ),
            "403": json_response(
                "Only internal non-superadmin users can view these security settings.",
                required=["message"],
                example={"message": "This action is unauthorized."},
            ),
        },
    },
    ("/api/v1/settings/security/2fa/app/start", "post"): {
        "summary": "Start authenticator app setup",
        "description": "Creates or replaces the current pending authenticator app setup for the authenticated owner and returns the stateless setup contract used by mobile.",
        "responses": {
            "201": json_response(
                "Authenticator app setup prepared successfully.",
                required=["message", "two_factor"],
                example={
                    "message": "Authentificateur en preparation.",
                    "two_factor": {
                        "required": True,
                        "enabled": False,
                        "method": "email",
                        "has_app": False,
                        "can_configure": True,
                        "app_setup": {
                            "setup_token": "setup_token_123",
                            "secret": "JBSWY3DPEHPK3PXP",
                            "otpauth_url": "otpauth://totp/Malikia%20Pro:owner%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Malikia+Pro&period=30&digits=6",
                            "expires_at": "2026-04-10T14:45:00Z",
                        },
                        "email": "owner@example.com",
                        "phone_hint": "+*******0123",
                        "last_sent_at": None,
                        "sms": {
                            "available": True,
                            "has_phone": True,
                            "phone_hint": "+*******0123",
                            "twilio_configured": True,
                            "company_enabled": True,
                        },
                    },
                },
            ),
            "403": json_response(
                "Only the account owner can configure two-factor methods.",
                required=["message"],
                example={"message": "This action is unauthorized."},
            ),
        },
    },
    ("/api/v1/settings/security/2fa/app/confirm", "post"): {
        "summary": "Confirm authenticator app setup",
        "description": "Confirms a pending authenticator app setup using the setup token returned by the start endpoint and a valid TOTP code.",
        "requestBody": request_body(
            "Authenticator app confirmation payload.",
            {
                "setup_token": {"type": "string"},
                "code": {"type": "string"},
            },
            required=["setup_token", "code"],
            example={
                "setup_token": "setup_token_123",
                "code": "123456",
            },
        ),
        "responses": {
            "200": json_response(
                "Authenticator app enabled successfully.",
                required=["message", "two_factor"],
                example={
                    "message": "Authentificateur active.",
                    "two_factor": {
                        "required": True,
                        "enabled": True,
                        "method": "app",
                        "has_app": True,
                        "can_configure": True,
                        "app_setup": None,
                        "email": "owner@example.com",
                        "phone_hint": "+*******0123",
                        "last_sent_at": None,
                        "sms": {
                            "available": True,
                            "has_phone": True,
                            "phone_hint": "+*******0123",
                            "twilio_configured": True,
                            "company_enabled": True,
                        },
                    },
                },
            ),
            "422": json_response(
                "The setup token is missing or expired, or the TOTP code is invalid.",
                required=["message", "errors"],
                example={
                    "message": "Demarrez la configuration avant de valider.",
                    "errors": {
                        "setup_token": ["Demarrez la configuration avant de valider."],
                    },
                },
            ),
        },
    },
    ("/api/v1/settings/security/2fa/app/cancel", "post"): {
        "summary": "Cancel pending authenticator app setup",
        "description": "Clears the active pending authenticator app setup for the authenticated owner.",
        "responses": {
            "200": json_response(
                "Pending authenticator app setup canceled successfully.",
                required=["message", "two_factor"],
                example={
                    "message": "Configuration d authentificateur annulee.",
                    "two_factor": {
                        "required": True,
                        "enabled": False,
                        "method": "email",
                        "has_app": False,
                        "can_configure": True,
                        "app_setup": None,
                        "email": "owner@example.com",
                        "phone_hint": "+*******0123",
                        "last_sent_at": None,
                        "sms": {
                            "available": True,
                            "has_phone": True,
                            "phone_hint": "+*******0123",
                            "twilio_configured": True,
                            "company_enabled": True,
                        },
                    },
                },
            ),
        },
    },
    ("/api/v1/settings/security/2fa/email", "post"): {
        "summary": "Switch two-factor delivery to email",
        "description": "Switches the current owner back to email-based two-factor authentication and clears any pending authenticator app setup.",
        "responses": {
            "200": json_response(
                "Email-based two-factor authentication enabled successfully.",
                required=["message", "two_factor"],
                example={
                    "message": "2FA par email active.",
                    "two_factor": {
                        "required": True,
                        "enabled": True,
                        "method": "email",
                        "has_app": False,
                        "can_configure": True,
                        "app_setup": None,
                        "email": "owner@example.com",
                    },
                },
            ),
        },
    },
    ("/api/v1/settings/security/2fa/sms", "post"): {
        "summary": "Switch two-factor delivery to SMS",
        "description": "Switches the current owner to SMS-based two-factor authentication when the company setting, phone number, and Twilio configuration all allow it.",
        "responses": {
            "200": json_response(
                "SMS-based two-factor authentication enabled successfully.",
                required=["message", "two_factor"],
                example={
                    "message": "2FA par SMS active.",
                    "two_factor": {
                        "required": True,
                        "enabled": True,
                        "method": "sms",
                        "has_app": False,
                        "can_configure": True,
                        "app_setup": None,
                        "email": "owner@example.com",
                        "phone_hint": "+*******0123",
                        "last_sent_at": None,
                        "sms": {
                            "available": True,
                            "has_phone": True,
                            "phone_hint": "+*******0123",
                            "twilio_configured": True,
                            "company_enabled": True,
                        },
                    },
                },
            ),
            "422": json_response(
                "SMS-based two-factor authentication is unavailable for the current owner or tenant setup.",
                required=["message", "errors"],
                example={
                    "message": "Activez d abord le 2FA SMS dans Parametres > Entreprise.",
                    "errors": {
                        "two_factor_method": ["Activez d abord le 2FA SMS dans Parametres > Entreprise."],
                    },
                },
            ),
        },
    },
    ("/api/v1/public/pricing", "get"): {
        "summary": "Get public pricing catalog",
        "description": "Returns the backend-authored public pricing catalog used by mobile pricing and upsell screens.",
        "parameters": [
            query_parameter("audience", {"type": "string", "enum": ["solo", "team"]}, "Optional audience filter.", example="team"),
            query_parameter("currency", {"type": "string", "enum": ["CAD", "USD", "EUR"]}, "Optional display currency.", example="USD"),
            query_parameter("include", {"type": "string"}, "Optional comma-separated includes.", example="comparison_sections"),
        ],
        "responses": {
            "200": json_response(
                "Public pricing catalog returned successfully.",
                required=["currency_code", "default_audience", "audience", "available_audiences", "highlighted_plan_key", "plans", "comparison_sections"],
                example={
                    "currency_code": "USD",
                    "default_audience": "team",
                    "audience": "team",
                    "available_audiences": ["solo", "team"],
                    "highlighted_plan_key": "growth",
                    "plans": [
                        {
                            "key": "starter",
                            "audience": "team",
                            "onboarding_enabled": True,
                            "prices_by_period": {
                                "monthly": {
                                    "billing_period": "monthly",
                                    "currency_code": "USD",
                                    "display_price": "$29.00/mo",
                                    "is_discounted": True,
                                    "promotion": {"is_active": True, "discount_percent": 25},
                                    "billing_subtitle": "Billed monthly",
                                },
                                "yearly": {
                                    "billing_period": "yearly",
                                    "currency_code": "USD",
                                    "display_price": "$24.00/mo",
                                    "is_discounted": True,
                                    "promotion": {"is_active": True, "discount_percent": 35},
                                    "billing_subtitle": "For 12 months, billed annually",
                                },
                            },
                        }
                    ],
                    "comparison_sections": [{"id": "core-workflow", "title": "Core workflow"}],
                },
            ),
            "422": json_response(
                "Validation error for an unsupported audience or currency.",
                required=["message", "errors"],
                example={
                    "message": "The given data was invalid.",
                    "errors": {
                        "currency": ["The selected currency is invalid."],
                    },
                },
            ),
        },
    },
    ("/api/v1/onboarding", "get"): {
        "summary": "Get onboarding state",
        "description": "Returns the normalized onboarding bootstrap payload used by mobile onboarding flows.",
        "parameters": [
            query_parameter("plan", {"type": "string"}, "Optional preselected plan key.", example="solo_pro"),
            query_parameter("billing_period", {"type": "string", "enum": ["monthly", "yearly"]}, "Optional preselected billing period.", example="yearly"),
        ],
        "responses": {
            "200": json_response(
                "Normalized onboarding payload returned successfully.",
                required=["status", "message", "account", "onboarding", "preset", "plans", "plan_limits"],
                example={
                    "status": "ready",
                    "message": None,
                    "account": {
                        "is_authenticated": True,
                        "is_owner": True,
                        "onboarding_completed": False,
                        "onboarding_completed_at": None,
                    },
                    "onboarding": {
                        "state": "ready",
                        "can_complete": True,
                        "requires_checkout": True,
                        "selected_plan_key": "solo_pro",
                        "selected_billing_period": "yearly",
                        "supported_currencies": ["CAD", "USD"],
                    },
                    "preset": {
                        "company_name": "Acme Studio",
                        "currency_code": "CAD",
                        "company_type": "services",
                        "company_sector": "salon",
                        "company_team_size": 1,
                    },
                    "plans": [{"key": "solo_pro", "audience": "solo"}],
                    "plan_limits": {"solo_pro": {"team_members_min": 1, "team_members_max": 1}},
                },
            ),
        },
    },
    ("/api/v1/onboarding", "post"): {
        "summary": "Submit onboarding",
        "description": "Completes onboarding directly or starts a Stripe checkout flow depending on the current billing configuration.",
        "requestBody": request_body(
            "Onboarding company, plan, and invite data.",
            {
                "company_name": {"type": "string"},
                "company_type": {"type": "string", "enum": ["services", "products"]},
                "company_sector": {"type": "string"},
                "currency_code": {"type": "string", "nullable": True},
                "company_team_size": {"type": "integer", "nullable": True},
                "plan_key": {"type": "string", "nullable": True},
                "billing_period": {"type": "string", "nullable": True},
                "accept_terms": {"type": "boolean"},
                "two_factor_method": {"type": "string", "nullable": True},
                "invites": schema_array(
                    schema_object(
                        required=["name", "email", "role"],
                        properties={
                            "name": {"type": "string"},
                            "email": {"type": "string", "format": "email"},
                            "role": {"type": "string", "enum": ["admin", "member"]},
                        },
                    )
                ),
            },
            required=["company_name", "company_type", "company_sector"],
            example={
                "company_name": "Acme Studio",
                "company_type": "services",
                "company_sector": "salon",
                "currency_code": "CAD",
                "company_team_size": 1,
                "plan_key": "solo_pro",
                "billing_period": "yearly",
                "accept_terms": True,
                "two_factor_method": "email",
                "invites": [],
            },
        ),
        "responses": {
            "200": json_response(
                "Onboarding completed immediately or a checkout URL was generated.",
                example={"checkout_url": "https://checkout.stripe.test/onboarding-session"},
            ),
            "403": json_response(
                "Only the account owner can complete onboarding.",
                required=["message"],
                example={"message": "Only the account owner can complete onboarding."},
            ),
            "422": json_response(
                "Validation error while submitting onboarding.",
                required=["message", "errors"],
                example={
                    "message": "The given data was invalid.",
                    "errors": {
                        "plan_key": ["Billing is not configured yet."],
                    },
                },
            ),
        },
    },
    ("/api/v1/onboarding/billing", "get"): {
        "summary": "Finalize onboarding billing callback",
        "description": "Refreshes onboarding state after the external Stripe checkout returns to the app.",
        "parameters": [
            query_parameter("status", {"type": "string", "enum": ["success", "cancel"]}, "Checkout callback status.", example="success"),
            query_parameter("session_id", {"type": "string"}, "Stripe checkout session id required for success.", example="{CHECKOUT_SESSION_ID}"),
        ],
        "responses": {
            "200": json_response(
                "Onboarding checkout completed successfully.",
                required=["status", "message", "onboarding_completed", "user"],
                example={
                    "status": "success",
                    "message": "Onboarding completed.",
                    "onboarding_completed": True,
                    "user": {"id": 42, "company_name": "Acme Studio"},
                },
            ),
            "403": json_response(
                "Only the account owner can complete onboarding.",
                required=["status", "message", "onboarding_completed"],
                example={
                    "status": "forbidden",
                    "message": "Only the account owner can complete onboarding.",
                    "onboarding_completed": False,
                },
            ),
            "409": json_response(
                "Checkout was canceled.",
                required=["status", "message", "onboarding_completed"],
                example={
                    "status": "canceled",
                    "message": "Checkout canceled.",
                    "onboarding_completed": False,
                },
            ),
            "422": json_response(
                "Checkout callback could not be completed.",
                required=["status", "message", "onboarding_completed"],
                example={
                    "status": "error",
                    "message": "Checkout session is missing.",
                    "onboarding_completed": False,
                },
            ),
        },
    },
    ("/api/v1/settings/billing", "get"): {
        "summary": "Get billing summary",
        "description": "Returns the normalized mobile billing summary contract.",
        "parameters": [
            query_parameter("checkout", {"type": "string"}, "Optional checkout feedback marker.", example="success"),
            query_parameter("plan", {"type": "string"}, "Optional checkout feedback plan.", example="solo_pro"),
            query_parameter("billing_period", {"type": "string", "enum": ["monthly", "yearly"]}, "Optional checkout feedback billing period.", example="yearly"),
            query_parameter("credits", {"type": "string"}, "Optional assistant credits feedback marker.", example="cancel"),
            query_parameter("connect", {"type": "string"}, "Optional Stripe Connect feedback marker.", example="refresh"),
        ],
        "responses": {
            "200": json_response(
                "Normalized billing summary returned successfully.",
                required=["status", "billing", "subscription", "plan_catalog", "capabilities", "assistant", "provider_details", "payment_methods", "loyalty", "flow_state"],
                example={
                    "status": "ok",
                    "billing": {
                        "provider_effective": "stripe",
                        "provider_ready": True,
                        "tenant_currency_code": "CAD",
                    },
                    "subscription": {
                        "plan_key": "solo_pro",
                        "billing_period": "yearly",
                        "status": "active",
                    },
                    "plan_catalog": {
                        "plans": [{"key": "solo_pro", "audience": "solo"}],
                        "active_plan_key": "solo_pro",
                        "seat_quantity": 1,
                    },
                    "capabilities": {
                        "can_checkout": True,
                        "can_swap": True,
                        "can_open_portal": True,
                        "can_buy_assistant_credits": True,
                    },
                    "assistant": {"enabled": True, "credits": {"enabled": True, "balance": 40, "pack_size": 100}},
                    "provider_details": {"paddle": {}, "stripe_connect": {"enabled": True}},
                    "payment_methods": {"enabled_methods": ["cash"], "default_method": "cash"},
                    "loyalty": {"feature_enabled": False},
                    "flow_state": {
                        "checkout": {"status": "success", "plan_key": "solo_pro", "billing_period": "yearly"},
                        "assistant_credits": {"status": "cancel"},
                        "stripe_connect": {"status": "refresh"},
                    },
                },
            ),
            "403": json_response(
                "Only the account owner can access billing settings.",
                required=["message"],
                example={"message": "This action is unauthorized."},
            ),
        },
    },
    ("/api/v1/settings/billing", "put"): {
        "summary": "Update billing-related store settings",
        "description": "Updates payment method, tip, and loyalty settings used by the current tenant.",
        "requestBody": request_body(
            "Billing settings update payload.",
            {
                "payment_methods": schema_array({"type": "string"}),
                "default_payment_method": {"type": "string", "nullable": True},
                "cash_allowed_contexts": schema_array({"type": "string"}),
                "tips": schema_object(),
                "loyalty": schema_object(),
            },
            example={
                "payment_methods": ["cash", "card"],
                "default_payment_method": "card",
                "cash_allowed_contexts": ["pos", "invoice"],
                "tips": {
                    "max_percent": 20,
                    "default_percent": 15,
                    "allocation_strategy": "primary",
                },
                "loyalty": {
                    "is_enabled": True,
                    "points_per_currency_unit": 1,
                    "rounding_mode": "floor",
                    "points_label": "points",
                },
            },
        ),
        "responses": {
            "200": json_response(
                "Billing-related store settings updated successfully.",
                required=["message", "payment_methods", "default_payment_method", "cash_allowed_contexts", "payment_method_settings", "tips", "loyalty"],
                example={
                    "message": "Payment settings updated.",
                    "payment_methods": ["cash", "card"],
                    "default_payment_method": "card",
                    "cash_allowed_contexts": ["pos", "invoice"],
                    "payment_method_settings": {
                        "enabled_methods_internal": ["cash", "card"],
                        "default_method_internal": "card",
                    },
                    "tips": {
                        "max_percent": 20,
                        "default_percent": 15,
                    },
                    "loyalty": {
                        "feature_enabled": True,
                        "is_enabled": True,
                        "points_per_currency_unit": 1,
                    },
                },
            ),
            "422": json_response(
                "Validation error while updating payment, tips, or loyalty settings.",
                required=["message", "errors"],
                example={
                    "message": "The given data was invalid.",
                    "errors": {
                        "payment_methods.0": ["The selected payment_methods.0 is invalid."],
                    },
                },
            ),
        },
    },
    ("/api/v1/settings/billing/checkout", "post"): {
        "summary": "Start billing checkout",
        "description": "Creates a provider checkout session and returns a mobile-safe redirect contract with resolved plan details.",
        "requestBody": request_body(
            "Checkout request payload.",
            {
                "plan_key": {"type": "string", "nullable": True},
                "price_id": {"type": "string", "nullable": True},
                "billing_period": {"type": "string", "nullable": True},
                "success_url": {"type": "string", "nullable": True},
                "cancel_url": {"type": "string", "nullable": True},
            },
            example={
                "plan_key": "starter",
                "billing_period": "monthly",
                "success_url": "mlkpro://billing/subscription-success",
                "cancel_url": "mlkpro://billing/subscription-cancel",
            },
        ),
        "responses": {
            "200": json_response(
                "Checkout session created successfully.",
                required=["status", "action", "url", "resolved_plan", "return_urls"],
                example={
                    "status": "requires_redirect",
                    "action": "open_checkout",
                    "url": "https://checkout.stripe.test/subscription-mobile",
                    "resolved_plan": {
                        "plan_key": "starter",
                        "billing_period": "monthly",
                        "currency_code": "CAD",
                        "promotion_discount_percent": None,
                    },
                    "return_urls": {
                        "success_url": "mlkpro://billing/subscription-success?session_id={CHECKOUT_SESSION_ID}",
                        "cancel_url": "mlkpro://billing/subscription-cancel",
                    },
                },
            ),
            "422": json_response(
                "Business or validation error while starting checkout.",
                example=VALIDATION_ERROR_EXAMPLE,
            ),
        },
    },
    ("/api/v1/settings/billing/connect", "post"): {
        "summary": "Start Stripe Connect onboarding",
        "description": "Creates a Stripe Connect onboarding link and returns a mobile redirect action.",
        "responses": {
            "200": json_response(
                "Stripe Connect onboarding link created successfully.",
                required=["status", "action", "url"],
                example={
                    "status": "requires_redirect",
                    "action": "open_connect_onboarding",
                    "url": "https://connect.stripe.test/onboarding",
                },
            ),
            "400": json_response(
                "Stripe Connect is not configured for the platform.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "stripe_connect_not_configured",
                    "message": "Stripe Connect is not configured.",
                },
            ),
            "422": json_response(
                "Stripe Connect onboarding could not be started.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "stripe_connect_onboarding_failed",
                    "message": "Unable to start Stripe Connect onboarding.",
                },
            ),
        },
    },
    ("/api/v1/settings/billing/assistant-addon", "post"): {
        "summary": "Update assistant addon state",
        "description": "Enables or disables the assistant addon when the current subscription and provider allow it.",
        "requestBody": request_body(
            "Assistant addon update payload.",
            {"enabled": {"type": "boolean"}},
            required=["enabled"],
            example={"enabled": True},
        ),
        "responses": {
            "200": json_response(
                "Assistant addon state updated successfully.",
                required=["status", "action", "message", "enabled"],
                example={
                    "status": "success",
                    "action": "assistant_addon_updated",
                    "message": "Assistant IA mis a jour.",
                    "enabled": True,
                },
            ),
            "422": json_response(
                "Assistant addon update failed because of provider or subscription rules.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "assistant_unavailable_for_provider",
                    "message": "Assistant IA indisponible pour ce fournisseur.",
                },
            ),
        },
    },
    ("/api/v1/settings/billing/assistant-credits", "post"): {
        "summary": "Start assistant credits checkout",
        "description": "Creates a Stripe checkout session for assistant credits and returns a mobile redirect contract with pack details.",
        "requestBody": request_body(
            "Assistant credits checkout request payload.",
            {
                "packs": {"type": "integer", "nullable": True},
                "success_url": {"type": "string", "nullable": True},
                "cancel_url": {"type": "string", "nullable": True},
            },
            example={
                "packs": 3,
                "success_url": "mlkpro://billing/assistant-success",
                "cancel_url": "mlkpro://billing/assistant-cancel",
            },
        ),
        "responses": {
            "200": json_response(
                "Assistant credits checkout session created successfully.",
                required=["status", "action", "url", "credits", "return_urls"],
                example={
                    "status": "requires_redirect",
                    "action": "open_checkout",
                    "url": "https://checkout.stripe.test/assistant-credits",
                    "credits": {
                        "pack_count": 3,
                        "pack_size": 100,
                        "total_credits": 300,
                    },
                    "return_urls": {
                        "success_url": "mlkpro://billing/assistant-success?session_id={CHECKOUT_SESSION_ID}",
                        "cancel_url": "mlkpro://billing/assistant-cancel",
                    },
                },
            ),
            "422": json_response(
                "Assistant credits checkout could not be created.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "assistant_activation_required",
                    "message": "Activez l option IA avant d acheter des credits.",
                },
            ),
        },
    },
    ("/api/v1/settings/billing/swap", "post"): {
        "summary": "Swap subscription plan",
        "description": "Swaps the active subscription plan using the same backend plan selection and restriction rules as the web app.",
        "requestBody": request_body(
            "Plan swap request payload.",
            {
                "plan_key": {"type": "string", "nullable": True},
                "price_id": {"type": "string", "nullable": True},
                "billing_period": {"type": "string", "nullable": True},
                "success_url": {"type": "string", "nullable": True},
                "cancel_url": {"type": "string", "nullable": True},
            },
            example={
                "plan_key": "solo_pro",
                "billing_period": "yearly",
            },
        ),
        "responses": {
            "200": json_response(
                "Plan swap completed, redirected, or determined to be a no-op.",
                example={
                    "status": "success",
                    "action": "subscription_updated",
                    "message": "Plan updated.",
                    "plan_key": "solo_pro",
                    "billing_period": "yearly",
                    "resolved_plan": {
                        "plan_key": "solo_pro",
                        "billing_period": "yearly",
                        "currency_code": "CAD",
                    },
                },
            ),
            "422": json_response(
                "Plan swap failed because of billing rules or provider constraints.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "billing_plan_restricted",
                    "message": "Selected plan is restricted for the current workspace.",
                },
            ),
        },
    },
    ("/api/v1/settings/billing/portal", "post"): {
        "summary": "Open provider billing portal",
        "description": "Returns a redirect contract for the Stripe customer portal or the provider-managed payment update flow.",
        "responses": {
            "200": json_response(
                "Provider portal or payment update redirect prepared successfully.",
                required=["status", "action", "url"],
                example={
                    "status": "requires_redirect",
                    "action": "open_customer_portal",
                    "url": "https://billing.stripe.test/customer-portal",
                },
            ),
            "422": json_response(
                "Provider portal is unavailable for the current tenant or provider.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "billing_portal_unavailable",
                    "message": "Unable to open Stripe customer portal.",
                },
            ),
        },
    },
    ("/api/v1/settings/billing/payment-method", "post"): {
        "summary": "Prepare payment method update transaction",
        "description": "Creates a provider-specific payment method update contract that mobile can present inside the native app flow.",
        "responses": {
            "200": json_response(
                "Payment method update contract created successfully.",
                required=["status", "action", "transaction_id"],
                example={
                    "status": "ready",
                    "action": "present_payment_method_update",
                    "transaction_id": "txn_paddle_update_123",
                },
            ),
            "422": json_response(
                "Payment method update is not available for the current provider or subscription state.",
                required=["status", "code", "message"],
                example={
                    "status": "error",
                    "code": "billing_subscription_required",
                    "message": "No subscription found.",
                },
            ),
        },
    },
}


POSTMAN_REQUEST_OVERRIDES: dict[tuple[str, str], dict] = {
    ("api/v1/auth/me", "GET"): {
        "description": "Lean mobile bootstrap contract for identity, owner context, broad feature visibility, and platform or team membership metadata.",
    },
    ("api/v1/global-search", "GET"): {
        "description": "Grouped global search contract for customers, tasks, quotes, and employee directory results inside the current workspace.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/global-search?q=Search",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "global-search"],
            "query": [
                {"key": "q", "value": "Search"},
            ],
        },
    },
    ("api/v1/pipeline", "GET"): {
        "description": "Canonical mobile pipeline contract for request, quote, job, task, and invoice timeline flows. Use this JSON route instead of the web-only Inertia timeline wrapper.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/pipeline?entityType=task&entityId=202",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "pipeline"],
            "query": [
                {"key": "entityType", "value": "task"},
                {"key": "entityId", "value": "202"},
            ],
        },
    },
    ("api/v1/ai/images", "POST"): {
        "description": "Generate a store or product image through the mobile AI image workflow with the same free-usage and credit-consumption rules as the web app.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "prompt": "Generate a polished storefront hero image with plants and warm daylight.",
                    "context": "store",
                }
            ),
        },
    },
    ("api/v1/settings/security", "GET"): {
        "description": "Normalized security payload for mobile two-factor management and recent security activity.",
    },
    ("api/v1/settings/security/2fa/app/start", "POST"): {
        "description": "Start or replace the current pending authenticator app setup and receive the stateless setup token plus secret.",
    },
    ("api/v1/settings/security/2fa/app/confirm", "POST"): {
        "description": "Confirm the pending authenticator app setup using the setup token and a valid TOTP code.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "setup_token": "setup_token_123",
                    "code": "123456",
                }
            ),
        },
    },
    ("api/v1/settings/security/2fa/app/cancel", "POST"): {
        "description": "Cancel the current pending authenticator app setup for the authenticated owner.",
    },
    ("api/v1/settings/security/2fa/email", "POST"): {
        "description": "Switch the current owner back to email-based two-factor authentication.",
    },
    ("api/v1/settings/security/2fa/sms", "POST"): {
        "description": "Switch the current owner to SMS-based two-factor authentication when the tenant configuration allows it.",
    },
    ("api/v1/public/pricing", "GET"): {
        "description": "Public pricing catalog used by mobile pricing and upsell surfaces.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/public/pricing?audience=team&currency=USD&include=comparison_sections",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "public", "pricing"],
            "query": [
                {"key": "audience", "value": "team"},
                {"key": "currency", "value": "USD"},
                {"key": "include", "value": "comparison_sections"},
            ],
        },
    },
    ("api/v1/onboarding", "GET"): {
        "description": "Normalized onboarding bootstrap contract for mobile.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/onboarding?plan=solo_pro&billing_period=yearly",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "onboarding"],
            "query": [
                {"key": "plan", "value": "solo_pro"},
                {"key": "billing_period", "value": "yearly"},
            ],
        },
    },
    ("api/v1/onboarding", "POST"): {
        "description": "Submit onboarding details or start onboarding checkout.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "company_name": "Acme Studio",
                    "company_type": "services",
                    "company_sector": "salon",
                    "currency_code": "CAD",
                    "company_team_size": 1,
                    "plan_key": "solo_pro",
                    "billing_period": "yearly",
                    "accept_terms": True,
                    "two_factor_method": "email",
                    "invites": [],
                }
            ),
        },
    },
    ("api/v1/onboarding/billing", "GET"): {
        "description": "Refresh onboarding status after an external Stripe checkout return.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/onboarding/billing?status=success&session_id={CHECKOUT_SESSION_ID}",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "onboarding", "billing"],
            "query": [
                {"key": "status", "value": "success"},
                {"key": "session_id", "value": "{CHECKOUT_SESSION_ID}"},
            ],
        },
    },
    ("api/v1/settings/billing", "GET"): {
        "description": "Normalized mobile billing summary contract.",
        "url": {
            "raw": "{{baseUrl}}/api/v1/settings/billing?checkout=success&plan=solo_pro&billing_period=yearly&credits=cancel&connect=refresh",
            "host": ["{{baseUrl}}"],
            "path": ["api", "v1", "settings", "billing"],
            "query": [
                {"key": "checkout", "value": "success"},
                {"key": "plan", "value": "solo_pro"},
                {"key": "billing_period", "value": "yearly"},
                {"key": "credits", "value": "cancel"},
                {"key": "connect", "value": "refresh"},
            ],
        },
    },
    ("api/v1/settings/billing", "PUT"): {
        "description": "Update store payment, tips, and loyalty settings from mobile.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "payment_methods": ["cash", "card"],
                    "default_payment_method": "card",
                    "cash_allowed_contexts": ["pos", "invoice"],
                    "tips": {
                        "max_percent": 20,
                        "default_percent": 15,
                        "allocation_strategy": "primary",
                    },
                    "loyalty": {
                        "is_enabled": True,
                        "points_per_currency_unit": 1,
                        "rounding_mode": "floor",
                        "points_label": "points",
                    },
                }
            ),
        },
    },
    ("api/v1/settings/billing/checkout", "POST"): {
        "description": "Start a billing checkout flow and receive a mobile redirect contract.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "plan_key": "starter",
                    "billing_period": "monthly",
                    "success_url": "mlkpro://billing/subscription-success",
                    "cancel_url": "mlkpro://billing/subscription-cancel",
                }
            ),
        },
    },
    ("api/v1/settings/billing/swap", "POST"): {
        "description": "Swap the active plan using the same backend plan resolution as the web.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "plan_key": "solo_pro",
                    "billing_period": "yearly",
                }
            ),
        },
    },
    ("api/v1/settings/billing/assistant-addon", "POST"): {
        "description": "Enable or disable the assistant addon when the plan and provider allow it.",
        "body": {
            "mode": "raw",
            "raw": pretty_json({"enabled": True}),
        },
    },
    ("api/v1/settings/billing/assistant-credits", "POST"): {
        "description": "Buy assistant credits and receive a redirect contract with mobile return URLs.",
        "body": {
            "mode": "raw",
            "raw": pretty_json(
                {
                    "packs": 3,
                    "success_url": "mlkpro://billing/assistant-success",
                    "cancel_url": "mlkpro://billing/assistant-cancel",
                }
            ),
        },
    },
    ("api/v1/settings/billing/connect", "POST"): {
        "description": "Start Stripe Connect onboarding for the current owner account.",
    },
    ("api/v1/settings/billing/portal", "POST"): {
        "description": "Open the provider customer portal or provider-managed payment update flow.",
    },
    ("api/v1/settings/billing/payment-method", "POST"): {
        "description": "Create a provider-specific payment method update transaction contract.",
    },
}


def run_route_list() -> list[dict]:
    command = ["php", "artisan", "route:list", "--path=api/v1", "--json"]
    if os.name == "nt":
        command = ["cmd", "/c", *command]

    output = subprocess.check_output(
        command,
        text=True,
    )
    return json.loads(output)


def normalize_operation_id(value: str) -> str:
    cleaned = re.sub(r"[^A-Za-z0-9_]+", "_", value).strip("_")
    return cleaned or "operation"


def extract_tag(uri: str) -> str:
    parts = uri.strip("/").split("/")
    if len(parts) >= 3 and parts[0] == "api" and parts[1] == "v1":
        return parts[2]
    return "root"


def build_openapi(routes: list[dict]) -> dict:
    paths: dict[str, dict] = defaultdict(dict)
    used_operation_ids: set[str] = set()
    tags = set()

    for route in routes:
        uri = "/" + route["uri"].lstrip("/")
        methods = [m for m in route["method"].split("|") if m != "HEAD"]
        tag = extract_tag(route["uri"])
        tags.add(tag)

        parameters = []
        for match in re.finditer(r"\{(\w+)\}", uri):
            param_name = match.group(1)
            parameters.append(
                {
                    "name": param_name,
                    "in": "path",
                    "required": True,
                    "schema": {"type": "string"},
                }
            )

        action = route.get("action") or ""
        action_name = action.split("\\")[-1] if action else "action"
        summary_base = route.get("name") or action_name

        for method in methods:
            method_lower = method.lower()
            op_base = normalize_operation_id(f"{summary_base}_{method_lower}")
            op_id = op_base
            counter = 1
            while op_id in used_operation_ids:
                counter += 1
                op_id = f"{op_base}_{counter}"
            used_operation_ids.add(op_id)

            middleware = route.get("middleware") or []
            secured = any("Authenticate:sanctum" in item for item in middleware)

            status_code = "201" if method_lower == "post" else "200"

            paths[uri][method_lower] = {
                "tags": [tag],
                "summary": summary_base,
                "operationId": op_id,
                "parameters": parameters,
                "security": [] if not secured else [{"bearerAuth": []}],
                "responses": {
                    status_code: {
                        "description": "Success",
                    }
                },
                "x-action": action,
                "x-middleware": middleware,
            }

            override = OPENAPI_OPERATION_OVERRIDES.get((uri, method_lower))
            if override:
                paths[uri][method_lower] = deep_merge(paths[uri][method_lower], override)

    return {
        "openapi": "3.0.3",
        "info": {
            "title": "Malikia pro API",
            "version": "v1",
            "description": "Auto-generated from routes/api.php (internal API v1).",
        },
        "servers": [
            {"url": "http://localhost", "description": "Local dev"},
        ],
        "tags": [{"name": tag} for tag in sorted(tags)],
        "components": {
            "securitySchemes": {
                "bearerAuth": {
                    "type": "http",
                    "scheme": "bearer",
                    "bearerFormat": "JWT",
                }
            }
        },
        "paths": dict(paths),
    }


def build_postman(routes: list[dict]) -> dict:
    grouped: dict[str, list[dict]] = defaultdict(list)

    for route in routes:
        tag = extract_tag(route["uri"])
        grouped[tag].append(route)

    items = []
    for tag in sorted(grouped.keys()):
        sub_items = []
        for route in grouped[tag]:
            uri = route["uri"]
            method = route["method"].split("|")[0]
            raw_url = "{{baseUrl}}/" + uri
            name = f"{method} /{uri}"

            request = {
                "method": method,
                "header": [
                    {"key": "Accept", "value": "application/json"},
                ],
                "url": {
                    "raw": raw_url,
                    "host": ["{{baseUrl}}"],
                    "path": uri.split("/"),
                },
                "description": f"Action: {route.get('action')}",
            }

            if method in {"POST", "PUT", "PATCH"}:
                request["header"].append({"key": "Content-Type", "value": "application/json"})
                request["body"] = {"mode": "raw", "raw": "{}"}

            secured = any(
                "Authenticate:sanctum" in item for item in (route.get("middleware") or [])
            )
            if not secured:
                request["auth"] = {"type": "noauth"}

            override = POSTMAN_REQUEST_OVERRIDES.get((uri, method))
            if override:
                request = deep_merge(request, override)

            sub_items.append({"name": name, "request": request})

        items.append({"name": tag, "item": sub_items})

    return {
        "info": {
            "name": "Malikia pro API v1",
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
            "description": "Auto-generated from routes/api.php (internal API v1).",
        },
        "variable": [
            {"key": "baseUrl", "value": "http://localhost"},
            {"key": "token", "value": ""},
        ],
        "auth": {
            "type": "bearer",
            "bearer": [
                {"key": "token", "value": "{{token}}", "type": "string"},
            ],
        },
        "item": items,
    }


def main() -> None:
    routes = run_route_list()
    openapi = build_openapi(routes)
    postman = build_postman(routes)

    output_dir = Path("docs") / "api"
    output_dir.mkdir(parents=True, exist_ok=True)

    openapi_path = output_dir / "openapi.json"
    postman_path = output_dir / "postman_collection.json"

    with open(openapi_path, "w", encoding="utf-8") as handle:
        json.dump(openapi, handle, indent=2)
        handle.write("\n")

    with open(postman_path, "w", encoding="utf-8") as handle:
        json.dump(postman, handle, indent=2)
        handle.write("\n")

    print(f"Wrote {openapi_path}")
    print(f"Wrote {postman_path}")


if __name__ == "__main__":
    main()
