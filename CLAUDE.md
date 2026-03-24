# ibinet-sdk - Shared SDK Package

## Purpose
Core shared package containing models, helpers, services, and middleware used by all Ibinet apps.

## Package Info
- **Name**: `san-teknologi-asia/ibinet-sdk`
- **Type**: `library`
- **Location**: `d:/Project/laragon/www/ibinet-sdk`
- **Apps using**: idc, ier, omc, ifos, ibinet-sso

## Contents

### Models (63 in `Ibinet\Models\`)
**Core Auth**: User, Role, Permission, RolePermission, Application, ApplicationModule
**Projects**: Project, ProjectRemote, ProjectWorkType, ProjectRequirement
**Remotes**: Remote, RemoteType, RemoteHelpdesk, RemoteFinance, RemoteSerial, RemotePic, RemoteTerritory, RemoteActiveHistory
**Geography**: Province, City, District, Village (Indonesia), Region, Zone
**Expenses**: ExpenseReport, ExpenseReportLocation, ExpenseReportRemote, ExpenseReportBalance, ExpenseReportRequest, ExpenseReportActivity, ExpenseCategory
**Tickets**: Ticket, TicketTimer, TicketRemote, TicketProtest
**Borrowing**: TechnicianBorrow, TechnicianBorrowRemote, TechnicianBorrowApproval, TechnicianBorrowContractChange
**Approvals**: ApprovalFlow, ApprovalFlowDetail, ApprovalFlowCondition, ApprovalActivity, ApprovalRevisionHistory
**Other**: Client, HardwareType, HardwareBrand, HardwareVariety, WorkType, WorkUnit, HomeBase, Link, Pic, Supervision, Mounting, Satelite, Transponder, Schedule, Notification, UserDevice, Setting, Activity

### Helpers (15 in `Ibinet\Helpers\`)
- **PermissionHelper**: `has($permission)` global function
- **UserHelper**: `getUserProjectByRoles($userId, $modules)`, region/homebase arrays
- **CustomHelper**: Status constants, badge colors
- **DatatableHelper**: HTML generation for DataTables
- **ExpenseReportHelper**: Code generators (ER, request, transaction codes)
- **TicketHelper**: Ticket creation, expense assignment
- **TechnicianBorrowHelper**: Borrow codes, availability checking
- **TimeHelper**: Work time calculation, stop clock
- **DateHelper**: Date parsing, formatting
- **FormHelper**: Image upload with watermark to S3
- **NotificationHelper**: Firebase FCM push notifications
- **ActivityHelper**: Expense report activity creation
- **MapsHelper**: Google Maps geocoding
- **RouteHelper**: SSO route generation
- **ConditionalHelper**: Role checking (SuperAdmin, Admin, Helpdesk, etc.)
- **SettingHelper**: `setting($key)` cached settings

### Services (2 in `Ibinet\Services\`)
- **ApprovalService** (~880 lines): Multi-step approval workflow for EXPENSE and FUND_REQUEST. Supports conditional branching (SAME-PROJECT, SAME-REGION, etc.), load balancing, revision history
- **TechnicianBorrowService** (~950 lines): Inter-project technician borrowing. Handles create, approve, contract changes, complete, cancel

### Middleware
- **SSOAuthenticate**: Redirects to SSO_URL if not authenticated

### Service Providers
- **PermissionServiceProvider**: Registers `@has()` and `@canany()` Blade directives

## Global Functions
```php
has('permission.string')           // Check role permission
setting('key')                     // Get cached setting
routeProfile()                     // SSO profile URL
routeLogout()                      // SSO logout URL
routeActive('routeName')           // Check active route
```

## Blade Directives
```blade
@has('omc.dashboard.index')
    {{-- Show content if user has permission --}}
@endhas

@canany(['permission1', 'permission2'])
    {{-- Show if user has any of the permissions --}}
@endcanany
```

## Important Notes
- **No migrations** in this package - all schema from app migrations (mainly idc)
- Uses UUID for all primary keys via boot event
- Spatie Activitylog used on: Client, Project, Remote, ExpenseReportRemote, Ticket, TicketTimer, Link, RemoteFinance, RemoteHelpdesk, ProjectRemote, ProjectRequirementValue
- SoftDeletes on most models
- **Use these models in all apps** - do not create local duplicates

## Work Types
- `WorkType` model represents CM (Conditional Maintenance) and PM (Periodic Maintenance)
- CM: No visit_number, one-off issue
- PM: Has visit_number tracking (1st, 2nd, 3rd visit...)
- Linked to ExpenseReportRemote via `work_type_id`

## Role Hierarchy
- `Role` has `parent_id` for hierarchy (e.g., PM → Coordinator → Technician)
- `Role::childrenRoles()` uses MySQL recursive CTE to get all descendants
- Used for approval workflows and technician assignment flow

## Permission Structure
```
Application (e.g., 'omc')
  └── ApplicationModule (e.g., 'omc.ticket')
        └── Permission (e.g., 'omc.ticket.create')

Role
  └── RolePermission → Permission
```

## Environment Variables Needed
```
SSO_URL=https://sso.ibinet.net
AWS_BASE_URL=...
AWS_APP_CODE=...
FIREBASE_CREDENTIALS=...
GOOGLE_MAPS_API_KEY=...
ROLE_SUPER_ADMIN=...
ROLE_ADMIN=...
ROLE_HELPDESK=...
ROLE_SUPERVISOR_ADMIN=...
ROLE_SUPERVISOR_HELPDESK=...
```

## Composer.json
```json
{
    "name": "san-teknologi-asia/ibinet-sdk",
    "require": {
        "php": "^8.1",
        "kreait/firebase-php": "^7.13",
        "spatie/laravel-activitylog": "^4.9.1",
        "league/flysystem-aws-s3-v3": "^3.29",
        "intervention/image": "^3.9",
        "ixudra/curl": "6.*",
        "maatwebsite/excel": "^3.1"
    }
}
```
