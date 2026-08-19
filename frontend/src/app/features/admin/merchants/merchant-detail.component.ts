import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatDividerModule } from '@angular/material/divider';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatTooltipModule } from '@angular/material/tooltip';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslateModule, TranslateService } from '@ngx-translate/core';
import { Observable } from 'rxjs';

import { MerchantStatus } from '../../../core/models/auth.model';
import { AdminMerchant, SubscriptionPlan } from '../../../core/models/merchant.model';
import { AdminMerchantService } from '../../../core/services/admin-merchant.service';
import { NotificationService } from '../../../core/services/notification.service';
import {
  DecisionDialogComponent,
  DecisionDialogData,
  DecisionDialogResult,
} from './decision-dialog.component';

/**
 * The review screen of BRD 8.1 step 5: approve, decline, or suspend an account.
 *
 * Which buttons appear follows the same state machine the server enforces, so a
 * transition that would be rejected is never offered.
 */
@Component({
  selector: 'app-merchant-detail',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatDatepickerModule,
    MatDialogModule,
    MatDividerModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    MatTooltipModule,
  ],
  templateUrl: './merchant-detail.component.html',
})
export class MerchantDetailComponent {
  private readonly merchants = inject(AdminMerchantService);
  private readonly notifications = inject(NotificationService);
  private readonly dialog = inject(MatDialog);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);
  private readonly translate = inject(TranslateService);

  private readonly id = Number(this.route.snapshot.paramMap.get('id'));

  readonly merchant = signal<AdminMerchant | null>(null);
  readonly plans = signal<SubscriptionPlan[]>([]);
  readonly loading = signal(true);
  readonly working = signal(false);

  /** Mirrors MerchantStatusService::TRANSITIONS so the UI offers only what works. */
  readonly canActivate = computed(() => {
    const status = this.merchant()?.status;

    return status === 'pending' || status === 'suspended';
  });

  readonly canReject = computed(() => this.merchant()?.status === 'pending');
  readonly canSuspend = computed(() => this.merchant()?.status === 'active');

  /** BRD FR-MER-02 blocks approval until both codes are confirmed. */
  readonly blockedByVerification = computed(
    () => this.merchant()?.status === 'pending' && !this.merchant()?.is_verified
  );

  readonly planForm = this.fb.nonNullable.group({
    subscription_plan_id: [0, [Validators.required, Validators.min(1)]],
    subscription_ends_at: ['', [Validators.required]],
  });

  constructor() {
    this.load();

    this.merchants.plans().subscribe({
      next: (plans) => this.plans.set(plans),
      error: () => this.plans.set([]),
    });
  }

  activate(): void {
    const merchant = this.merchant();

    if (!merchant) {
      return;
    }

    const isRestore = merchant.status === 'suspended';

    this.confirm({
      titleKey: isRestore ? 'admin.restoreTitle' : 'admin.activateTitle',
      bodyKey: isRestore ? 'admin.restoreBody' : 'admin.activateBody',
      confirmKey: isRestore ? 'admin.restore' : 'admin.activate',
      merchantName: merchant.name,
      // Activation needs no justification; only refusals do.
      requiresReason: false,
    }).subscribe((result) => {
      if (result) {
        this.apply(this.merchants.activate(this.id), 'admin.activated');
      }
    });
  }

  reject(): void {
    const merchant = this.merchant();

    if (!merchant) {
      return;
    }

    this.confirm({
      titleKey: 'admin.rejectTitle',
      bodyKey: 'admin.rejectBody',
      confirmKey: 'admin.reject',
      merchantName: merchant.name,
      requiresReason: true,
      destructive: true,
    }).subscribe((result) => {
      if (result) {
        this.apply(this.merchants.reject(this.id, result.reason), 'admin.rejected');
      }
    });
  }

  suspend(): void {
    const merchant = this.merchant();

    if (!merchant) {
      return;
    }

    this.confirm({
      titleKey: 'admin.suspendTitle',
      bodyKey: 'admin.suspendBody',
      confirmKey: 'admin.suspend',
      merchantName: merchant.name,
      requiresReason: true,
      destructive: true,
    }).subscribe((result) => {
      if (result) {
        this.apply(this.merchants.suspend(this.id, result.reason), 'admin.suspended');
      }
    });
  }

  /**
   * True when the account is open but its owner has never set a password — the
   * one state where the store exists yet nobody can get into it.
   */
  readonly ownerCannotSignIn = computed(() => {
    const merchant = this.merchant();

    return merchant?.status === 'active' && merchant.owner?.has_password === false;
  });

  resendInvitation(): void {
    const merchant = this.merchant();

    if (!merchant) {
      return;
    }

    this.confirm({
      titleKey: 'admin.resendInvitationTitle',
      bodyKey: 'admin.resendInvitationBody',
      confirmKey: 'admin.resendInvitation',
      merchantName: merchant.owner?.email ?? merchant.email,
      requiresReason: false,
    }).subscribe((result) => {
      if (result) {
        this.apply(this.merchants.resendInvitation(this.id), 'admin.invitationSent');
      }
    });
  }

  savePlan(): void {
    if (this.planForm.invalid) {
      this.planForm.markAllAsTouched();
      return;
    }

    const { subscription_plan_id, subscription_ends_at } = this.planForm.getRawValue();

    this.apply(
      this.merchants.assignPlan(this.id, subscription_plan_id, this.asDate(subscription_ends_at)),
      'admin.planUpdated'
    );
  }

  back(): void {
    void this.router.navigate(['/admin/merchants']);
  }

  statusClass(status: MerchantStatus): string {
    return {
      pending: 'bg-amber-100 text-amber-900',
      active: 'bg-emerald-100 text-emerald-900',
      suspended: 'bg-orange-100 text-orange-900',
      rejected: 'bg-red-100 text-red-900',
    }[status];
  }

  /**
   * Plan caps for the summary line. A null cap means unlimited (BRD FR-ADM-04),
   * so it is turned into words rather than shown as an empty value.
   */
  capsFor(plan: SubscriptionPlan): Record<string, string> {
    const unlimited = this.translate.instant('admin.unlimited');

    return {
      branches: plan.max_branches?.toString() ?? unlimited,
      users: plan.max_users?.toString() ?? unlimited,
      invoices: plan.max_monthly_invoices?.toString() ?? unlimited,
    };
  }

  private confirm(data: DecisionDialogData): Observable<DecisionDialogResult | undefined> {
    return this.dialog
      .open<DecisionDialogComponent, DecisionDialogData, DecisionDialogResult>(
        DecisionDialogComponent,
        { data, width: '460px', autoFocus: data.requiresReason ? 'first-tabbable' : 'dialog' }
      )
      .afterClosed();
  }

  private apply(request: Observable<AdminMerchant>, successKey: string): void {
    this.working.set(true);

    request.subscribe({
      next: (merchant) => {
        this.merchant.set(merchant);
        this.syncPlanForm(merchant);
        this.working.set(false);
        this.notifications.success(successKey);
      },
      // The interceptor already surfaced the message, including the 409 a stale
      // screen would produce; reloading brings this view back in step.
      error: () => {
        this.working.set(false);
        this.load();
      },
    });
  }

  private load(): void {
    this.loading.set(true);

    this.merchants.get(this.id).subscribe({
      next: (merchant) => {
        this.merchant.set(merchant);
        this.syncPlanForm(merchant);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.back();
      },
    });
  }

  private syncPlanForm(merchant: AdminMerchant): void {
    this.planForm.patchValue({
      subscription_plan_id: merchant.subscription_plan?.id ?? 0,
      subscription_ends_at: merchant.subscription_ends_at ?? '',
    });
  }

  /** The datepicker hands back a Date; the API expects a plain YYYY-MM-DD. */
  private asDate(value: string | Date): string {
    const date = value instanceof Date ? value : new Date(value);

    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, '0'),
      String(date.getDate()).padStart(2, '0'),
    ].join('-');
  }
}
