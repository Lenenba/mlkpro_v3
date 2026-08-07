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

Any change to plans, sectors, feature flags, module navigation, shared screens, demos, or module-specific permissions must follow the [module visibility and consistency audit runbook](docs/MODULE_VISIBILITY_AUDIT_RUNBOOK.md). Record dated audit evidence under `docs/audits/module-visibility/` when the change requires a cross-module review.

For every PHP addition, edit, or deletion, finish staging the final contents and run `composer qa:format`, then run it again immediately before pushing or handing off the branch. The formatter gate must reject partially staged PHP files and unstaged PHP deletions. A PHP branch is not ready to merge until the `laravel-quality` check is green on that exact commit. If PHP, Composer, or Pint is unavailable locally, report the validation as blocked and wait for the CI result instead of claiming success.

No secret, real authentication token, one-time code, full customer phone number, or direct customer data may be committed or copied into validation evidence.
