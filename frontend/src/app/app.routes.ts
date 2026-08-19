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
