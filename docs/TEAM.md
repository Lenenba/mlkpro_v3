# Team module (assignments + permissions)

## Overview
This module lets an account owner:
- Create team member login accounts (employees).
- Configure what each team member can do (permissions).
- Assign team members to specific jobs (works).

Team members can then log in and access only the jobs they are assigned to, based on their permissions.

## Data model

### `team_members`
Links an employee user account to an account owner, with profile info and permissions.

Columns (main):
- `account_id` (FK `users.id`): the account owner.
- `user_id` (FK `users.id`): the employee login user.
- `title`, `phone`
- `permissions` (json array of strings)
- `is_active` (bool)

### `work_team_members`
Assignment pivot between jobs and team members.

Columns:
- `work_id` (FK `works.id`)
- `team_member_id` (FK `team_members.id`)
- `role` (optional string)

## Permissions
Stored on `team_members.permissions`:
- `jobs.view`: view assigned jobs
- `jobs.edit`: edit assigned jobs

Notes:
- The current implementation enforces permissions for job view/edit via `app/Policies/WorkPolicy.php`.
- You can extend this list later (ex: `jobs.update_status`, `invoices.create`, etc.) by updating:
  - `app/Http/Controllers/TeamMemberController.php` (available permissions)
  - relevant Policies/Controllers

## UI / Routes

### Team management
- `GET /team` (`team.index`): list/edit members (account owner only)
- `POST /team` (`team.store`): create a member (account owner only)
- `PUT /team/{teamMember}` (`team.update`): update a member (account owner only)
- `DELETE /team/{teamMember}` (`team.destroy`): deactivates a member (account owner only)

Front-end page:
- `resources/js/Pages/Team/Index.vue`

### Job assignment
On job create/edit:
- Assign team members using the **TEAM** section in `resources/js/Pages/Work/Create.vue`.
- The form submits `team_member_ids[]` and syncs assignments in `app/Http/Controllers/WorkController.php`.

## Seed data
Seeder:
- `database/seeders/TeamModuleSeeder.php`

Run everything:
- `php artisan migrate:fresh --seed`

Seed only the team module:
- `php artisan db:seed --class=Database\\\\Seeders\\\\TeamModuleSeeder`

## Current limitations / next improvements
- Permissions are currently enforced for jobs only; other modules (customers/products/quotes/invoices) can be extended similarly.
- Deactivating a member removes their job assignments and blocks access.
- You may want to add “invite/reset password” workflow (email, magic link, etc.) instead of sharing a temporary password.

