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
    { route: '/dashboard', labelKey: 'nav.dashboard', icon: 'dashboard' },
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
