import { Routes } from '@angular/router';

import { authGuard } from './core/guards/auth.guard';
import { guestGuard } from './core/guards/guest.guard';
import { requirePermission } from './core/guards/permission.guard';
import { ShellComponent } from './layout/shell/shell.component';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    // Merchant self-registration (BRD 8.1). Public by design — the applicant has
    // no account yet, and the one it creates grants nothing until activation.
    path: 'register',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/register/register.component').then((m) => m.RegisterComponent),
  },
  {
    path: 'forgot-password',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/auth/forgot-password/forgot-password.component').then(
        (m) => m.ForgotPasswordComponent
      ),
  },
  {
    // Where an invitation link lands (BRD FR-BRN-04). The token is the
    // authorisation, so no session is required to reach it.
    path: 'set-password/:token',
    loadComponent: () =>
      import('./features/auth/set-password/set-password.component').then(
        (m) => m.SetPasswordComponent
      ),
  },
  {
    // Everything inside the shell needs a signed-in user. Feature areas of the
    // later phases are added as children here.
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        // The till (BRD 8.4). Open to every role that serves a customer, which is
        // why it leads the navigation and is where a sales rep lands.
        path: 'pos',
        canActivate: [requirePermission('invoices.create')],
        loadComponent: () =>
          import('./features/sales/point-of-sale/point-of-sale.component').then(
            (m) => m.PointOfSaleComponent
          ),
      },
      {
        // Reading a customer's position without recording a sale (BRD 8.5).
        path: 'customers',
        canActivate: [requirePermission('customers.lookup')],
        loadComponent: () =>
          import('./features/sales/customer-lookup/customer-lookup.component').then(
            (m) => m.CustomerLookupComponent
          ),
      },
      {
        // The anti-fraud signals of BRD 12. A tighter gate than the reports:
        // reports.view_all_branches is the owner's alone, and a branch manager must
        // not read a screen that examines them.
        path: 'alerts',
        canActivate: [requirePermission('reports.view_all_branches')],
        loadComponent: () =>
          import('./features/alerts/alerts.component').then((m) => m.AlertsComponent),
      },
      {
        // The audit trail (BRD FR-SEC-02, section 20). Read-only, and by BRD 7.2
        // only the owner and the platform supervisor reach it.
        path: 'audit-log',
        canActivate: [requirePermission('audit_logs.view')],
        loadComponent: () =>
          import('./features/audit/audit-log.component').then((m) => m.AuditLogComponent),
      },
      {
        // The signed-in user's own account. No permission gate: everyone has one.
        path: 'profile',
        loadComponent: () =>
          import('./features/profile/profile.component').then((m) => m.ProfileComponent),
      },
      {
        // The store's own settings (BRD FR-MER-05, FR-MER-06) — the owner alone,
        // since a branch manager editing the trade name would be editing the brand.
        path: 'settings',
        canActivate: [requirePermission('merchant.profile')],
        loadComponent: () =>
          import('./features/settings/settings.component').then((m) => m.SettingsComponent),
      },
      {
        // The reports of BRD 9. One gate for all five; a branch manager reaches the
        // same screen and the server answers with their own branch.
        path: 'reports',
        canActivate: [requirePermission('reports.view_own_branch')],
        loadComponent: () =>
          import('./features/reports/reports.component').then((m) => m.ReportsComponent),
      },
      {
        // Deciding on correction requests (BRD 8.7). Managers and the owner only;
        // a rep raises requests from the customer card instead.
        path: 'corrections',
        canActivate: [requirePermission('invoices.amend')],
        loadComponent: () =>
          import('./features/sales/corrections/correction-queue.component').then(
            (m) => m.CorrectionQueueComponent
          ),
      },
      {
        // The store owner's own setup (BRD 8.2). Guards mirror the matrix of
        // BRD 7.2; the API refuses these calls for every other role regardless.
        path: 'branches',
        canActivate: [requirePermission('branches.manage')],
        loadComponent: () =>
          import('./features/merchant/branches/branch-list.component').then(
            (m) => m.BranchListComponent
          ),
      },
      {
        path: 'loyalty-rule',
        canActivate: [requirePermission('loyalty_rules.manage')],
        loadComponent: () =>
          import('./features/merchant/loyalty-rule/loyalty-rule.component').then(
            (m) => m.LoyaltyRuleComponent
          ),
      },
      {
        path: 'staff',
        canActivate: [requirePermission('users.manage')],
        loadComponent: () =>
          import('./features/merchant/staff/staff-list.component').then(
            (m) => m.StaffListComponent
          ),
      },
      {
        // Platform supervisor console. The guard only avoids a pointless
        // navigation; the API refuses these calls for every other role.
        path: 'admin/merchants',
        canActivate: [requirePermission('merchants.manage_status')],
        loadComponent: () =>
          import('./features/admin/merchants/merchant-list.component').then(
            (m) => m.MerchantListComponent
          ),
      },
      {
        path: 'admin/merchants/:id',
        canActivate: [requirePermission('merchants.manage_status')],
        loadComponent: () =>
          import('./features/admin/merchants/merchant-detail.component').then(
            (m) => m.MerchantDetailComponent
          ),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
