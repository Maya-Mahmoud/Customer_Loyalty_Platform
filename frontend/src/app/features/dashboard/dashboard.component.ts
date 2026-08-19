import { Component, inject } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatIconModule } from '@angular/material/icon';
import { TranslateModule } from '@ngx-translate/core';

import { AuthService } from '../../core/services/auth.service';

/**
 * Placeholder home screen. It shows what the session actually resolved to —
 * role, merchant, branch, permissions — which is what the reports and KPI
 * dashboard of BRD FR-RPT-01 will replace in a later phase.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [TranslateModule, MatCardModule, MatChipsModule, MatIconModule],
  templateUrl: './dashboard.component.html',
})
export class DashboardComponent {
  private readonly auth = inject(AuthService);

  readonly user = this.auth.user;
  readonly merchant = this.auth.merchant;
  readonly branch = this.auth.branch;
}
