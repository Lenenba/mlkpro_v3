# Contributing to MLK Pro

## Mandatory Git workflow

`develop` is the integration branch and the only allowed base for development work.

1. Update the local `develop` branch with `git pull --ff-only origin develop`.
2. Create a dedicated branch from `develop`.
3. Make and validate the changes on that dedicated branch.
4. Open the pull request with `develop` as its base.
5. Merge into `develop` only after the applicable quality gates pass.

Example:

```powershell
git switch develop
git pull --ff-only origin develop
git switch -c feature/short-description
gh pr create --base develop --draft
```

## Protected `main` branch

- Only the repository owner, Jules Roger Sombangnen, may push or merge into `main`.
- Contributors and automated agents must never commit directly on `main`.
- Contributors and automated agents must never run `git push origin main`.
- Automated pull requests must never target `main`.
- Release or promotion operations from `develop` to `main` are performed exclusively by the repository owner.
- If work appears to require `main`, stop at a validated branch or pull request targeting `develop` and hand control back to the owner.

## Quality gates

Every change must include tests proportionate to its risk and preserve existing workflows. The controlled improvement program uses the detailed protocol in [QUALITY_GATES.md](docs/audits/mlkpro-benchmark-2026-07-16/execution/QUALITY_GATES.md).

No secret, real authentication token, one-time code, full customer phone number, or direct customer data may be committed or copied into validation evidence.
