# UAT User Accounts

This project provides a dedicated UAT seeder for user-management role and branch scenarios.

## Safety Rules

- Seeder is allowed only in `local` and `staging` environments.
- Seeder is blocked in `production` by design.
- Seeder never creates branch records.
- Seeder fails fast when required real branches are missing.

## Required Branches Before Running

The seeder validates these branches from `branches` table:

- Branch code normalized to `01` and branch name containing `หนองบัวลำภู` or `Nong Bua Lamphu`
- Branch code normalized to `02` (any real branch name)

If any required branch is missing, the seeder aborts with a clear error message.

## One-Time Run Command

```bash
php artisan db:seed --class=Database\\Seeders\\UatUserSeeder
```

## Seeded UAT Accounts

Default password for all accounts: `password`

- UAT Owner Branch 01
  - employee_code: `UAT-OWNER-01`
  - email: `uat.owner01@garment-erp.local`
  - role: `OWNER`
  - branch: `01 หนองบัวลำภู`
  - active: `true`
- UAT Admin System Branch 01
  - employee_code: `UAT-ADMSYS-01`
  - email: `uat.adminsystem01@garment-erp.local`
  - role: `ADMIN_SYSTEM`
  - branch: `01 หนองบัวลำภู`
  - active: `true`
- UAT Admin System Branch 02
  - employee_code: `UAT-ADMSYS-02`
  - email: `uat.adminsystem02@garment-erp.local`
  - role: `ADMIN_SYSTEM`
  - branch: `02`
  - active: `true`
- UAT Counter Branch 02
  - employee_code: `UAT-COUNTER-02`
  - email: `uat.counter02@garment-erp.local`
  - role: `COUNTER`
  - branch: `02`
  - active: `true`
- UAT Admin Production Branch 02
  - employee_code: `UAT-ADMPROD-02`
  - email: `uat.adminproduction02@garment-erp.local`
  - role: `ADMIN_PRODUCTION`
  - branch: `02`
  - active: `true`
- UAT QC Staff Branch 02
  - employee_code: `UAT-QC-02`
  - email: `uat.qc02@garment-erp.local`
  - role: `QC_STAFF`
  - branch: `02`
  - active: `true`
- UAT Delivery Staff Branch 02
  - employee_code: `UAT-DELIVERY-02`
  - email: `uat.delivery02@garment-erp.local`
  - role: `DELIVERY_STAFF`
  - branch: `02`
  - active: `true`

## UAT 5-Core Case Mapping

- Case 1: OWNER branch 01 can access user management and filter branch 02
  - login: `UAT-OWNER-01`
- Case 2: ADMIN_SYSTEM branch 01 can view branch 02 users
  - login: `UAT-ADMSYS-01`
- Case 3: ADMIN_SYSTEM branch 02 cannot filter to branch 01
  - login: `UAT-ADMSYS-02`
- Case 4: ADMIN_SYSTEM cannot assign OWNER role
  - actor login: `UAT-ADMSYS-01` or `UAT-ADMSYS-02`
- Case 5: COUNTER cannot access user management page
  - login: `UAT-COUNTER-02`
