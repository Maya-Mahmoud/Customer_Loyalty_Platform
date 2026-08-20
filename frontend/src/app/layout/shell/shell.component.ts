import { Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { Permission } from '../../core/models/auth.model';
import { AuthService } from '../../core/services/auth.service';
import { LanguageService } from '../../core/services/language.service';

interface NavItem {
  route: string;
  labelKey: string;
  icon: string;
  /** Omitted when every signed-in role may see the entry. */
  permissions?: Permission[];
}

/**
 * Frame around every authenticated screen: navigation on the side, identity and
 * language on top. Sits behind authGuard, so the user is always loaded here.
 */
@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    TranslateModule,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatListModule,
    MatMenuModule,
    MatSidenavModule,
    MatToolbarModule,
  ],
  templateUrl: './shell.component.html',
})
export class ShellComponent {
  private readonly auth = inject(AuthService);
  private readonly language = inject(LanguageService);

  readonly user = this.auth.user;
  readonly merchant = this.auth.merchant;
  readonly branch = this.auth.branch;
  readonly currentLanguage = this.language.current;

  /**
   * Grows with each phase. Entries the role cannot use are hidden rather than
   * disabled, so a sales rep never sees a reports link they cannot open.
   */
  private readonly navItems: NavItem[] = [
    /*
     * The till comes first: it is the screen a sales rep spends their whole day
     * in, and the only one some roles ever need.
     */
    {
      route: '/pos',
      labelKey: 'nav.pos',
      icon: 'point_of_sale',
      permissions: ['invoices.create'],
    },
    {
      route: '/customers',
      labelKey: 'nav.customers',
      icon: 'person_search',
      permissions: ['customers.lookup'],
    },
    {
      route: '/reports',
      labelKey: 'nav.reports',
      icon: 'insights',
      permissions: ['reports.view_own_branch'],
    },
    {
      route: '/corrections',
      labelKey: 'nav.corrections',
      icon: 'rule',
      permissions: ['invoices.amend'],
    },
    { route: '/dashboard', labelKey: 'nav.dashboard', icon: 'dashboard' },
    {
      route: '/branches',
      labelKey: 'nav.branches',
      icon: 'store',
      permissions: ['branches.manage'],
    },
    {
      route: '/loyalty-rule',
      labelKey: 'nav.loyaltyRule',
      icon: 'loyalty',
      permissions: ['loyalty_rules.manage'],
    },
    {
      route: '/staff',
      labelKey: 'nav.staff',
      icon: 'group',
      permissions: ['users.manage'],
    },
    {
      route: '/admin/merchants',
      labelKey: 'nav.merchants',
      icon: 'storefront',
      permissions: ['merchants.manage_status'],
    },
  ];

  readonly visibleNavItems = computed(() =>
    this.navItems.filter(
      (item) => item.permissions === undefined || this.auth.hasAny(item.permissions)
    )
  );

  readonly roleLabelKey = computed(() => `roles.${this.user()?.role ?? 'sales_rep'}`);

  toggleLanguage(): void {
    this.language.toggle();
  }

  logout(): void {
    this.auth.logout();
  }
}
