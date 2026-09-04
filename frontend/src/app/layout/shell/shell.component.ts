import { Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatTooltipModule } from '@angular/material/tooltip';
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
    MatSidenavModule,
    MatToolbarModule,
    MatTooltipModule,
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
   * Where the settings icon in the toolbar goes, or null when this account has
   * nothing to configure.
   *
   * Two different screens behind one icon, because "settings" means the thing the
   * signed-in person actually administers: their shop if they own one, the platform
   * itself if they run it. No account holds both permissions — by BRD 7.2 the
   * supervisor has no merchant — so the order below never has to arbitrate.
   *
   * Hidden rather than disabled for everyone else: a control that never does
   * anything is worse than no control at all.
   */
  readonly settingsRoute = computed<string | null>(() => {
    if (this.auth.has('merchants.manage_status')) {
      return '/admin/settings';
    }

    return this.auth.has('merchant.profile') ? '/settings' : null;
  });

  /** Names the screen the icon opens, rather than saying "settings" twice for two
      different things. */
  readonly settingsLabelKey = computed(() =>
    this.settingsRoute() === '/admin/settings' ? 'nav.platformSettings' : 'nav.settings'
  );

  /**
   * Grows with each phase. Entries the role cannot use are hidden rather than
   * disabled, so a sales rep never sees a reports link they cannot open.
   */
  private readonly navItems: NavItem[] = [
    /*
     * The home screen first, then the till.
     *
     * A rail whose first entry is not the landing page sends the user hunting for
     * the screen they were just looking at. The till follows immediately, because it
     * is where a sales rep spends the whole day and the only screen some roles ever
     * need — and a rep, who has no dashboard figures to read, still lands on it
     * directly at sign-in (see AuthService.homeRoute).
     */
    { route: '/dashboard', labelKey: 'nav.dashboard', icon: 'dashboard' },
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
      route: '/alerts',
      labelKey: 'nav.alerts',
      icon: 'shield',
      permissions: ['reports.view_all_branches'],
    },
    {
      route: '/audit-log',
      labelKey: 'nav.auditLog',
      icon: 'history',
      permissions: ['audit_logs.view'],
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
