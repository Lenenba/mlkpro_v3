import json
import os
import re
import subprocess
from collections import defaultdict
from pathlib import Path


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

    return {
        "openapi": "3.0.3",
        "info": {
            "title": "MLK Pro API",
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

            sub_items.append({"name": name, "request": request})

        items.append({"name": tag, "item": sub_items})

    return {
        "info": {
            "name": "MLK Pro API v1",
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
