import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatDividerModule } from '@angular/material/divider';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatRadioModule } from '@angular/material/radio';
import { MatSelectModule } from '@angular/material/select';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslateModule } from '@ngx-translate/core';

import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import {
  AccumulationScope,
  LoyaltyRule,
  LoyaltyRuleForm,
  ResetPolicy,
  ResetPolicy as ResetPolicyType,
  RewardType,
  ThresholdType,
} from '../../../core/models/loyalty-rule.model';
import { AuthService } from '../../../core/services/auth.service';
import { LoyaltyRuleService } from '../../../core/services/loyalty-rule.service';
import { NotificationService } from '../../../core/services/notification.service';

/**
 * The rule engine settings of BRD 8.3 — ten parameters that decide what every
 * customer earns.
 *
 * Two things shape this screen. Saving publishes a new version rather than editing
 * the current one (BR-015), so the wording says so and asks for a start date. And
 * the parameters interact — a percentage needs a ceiling, a voucher needs an
 * expiry, an amount threshold needs an amount — so fields appear only when they
 * apply, and a worked example shows what the current settings would actually pay.
 */
@Component({
  selector: 'app-loyalty-rule',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatDatepickerModule,
    MatDividerModule,
    MatExpansionModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatRadioModule,
    MatSelectModule,
    MatTableModule,
    MatTooltipModule,
  ],
  templateUrl: './loyalty-rule.component.html',
})
export class LoyaltyRuleComponent {
  private readonly fb = inject(FormBuilder);
  private readonly rules = inject(LoyaltyRuleService);
  private readonly notifications = inject(NotificationService);
  private readonly auth = inject(AuthService);

  readonly historyColumns = ['version', 'threshold', 'reward', 'period', 'author'];

  /** A version cannot start in the past (BR-015), so the picker starts today. */
  readonly today = new Date();

  readonly current = signal<LoyaltyRule | null>(null);
  readonly history = signal<LoyaltyRule[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  readonly form = this.fb.nonNullable.group({
    threshold_type: ['amount' as ThresholdType, [Validators.required]],
    threshold_amount: [1000 as number | null],
    threshold_invoice_count: [null as number | null],

    reward_type: ['percentage' as RewardType, [Validators.required]],
    reward_value: [10, [Validators.required, Validators.min(0.01)]],
    max_discount_amount: [50 as number | null],
    min_invoice_amount: [10, [Validators.required, Validators.min(0)]],

    accumulation_scope: ['merchant' as AccumulationScope, [Validators.required]],
    reset_policy: ['carry_over' as ResetPolicyType, [Validators.required]],
    balance_validity_months: [12 as number | null],
    voucher_validity_days: [30 as number | null],

    effective_from: [new Date() as Date | string, [Validators.required]],
  });

  private readonly thresholdType = signal<ThresholdType>('amount');
  private readonly rewardType = signal<RewardType>('percentage');
  private readonly values = signal(this.form.getRawValue());

  readonly tracksAmount = computed(
    () => this.thresholdType() === 'amount' || this.thresholdType() === 'both'
  );

  readonly tracksInvoices = computed(
    () => this.thresholdType() === 'invoice_count' || this.thresholdType() === 'both'
  );

  /** Only a percentage scales with the cycle, so only it needs a ceiling (BR-021). */
  readonly needsCap = computed(() => this.rewardType() === 'percentage');

  readonly isVoucher = computed(() => this.rewardType() === 'voucher');

  /** True once a version exists, which changes the wording from "publish" to "replace". */
  readonly hasCurrent = computed(() => this.current() !== null);

  /**
   * What the current settings would actually pay on a worked example.
   *
   * A narrow reading of the simulator in FR-LOY-09 — one illustrative cycle rather
   * than a projection over real data — but it is the part that stops an owner
   * publishing a rule whose arithmetic surprises them.
   */
  readonly example = computed(() => {
    const v = this.values();
    const threshold = this.tracksAmount() ? Number(v.threshold_amount ?? 0) : 0;

    // A cycle that lands 16% past the threshold, mirroring BRD 11.2.
    const cycleTotal = this.tracksAmount() ? Math.round(threshold * 1.16) : 1160;

    const computedReward =
      v.reward_type === 'percentage'
        ? (cycleTotal * Number(v.reward_value ?? 0)) / 100
        : Number(v.reward_value ?? 0);

    const cap = this.needsCap() ? Number(v.max_discount_amount ?? 0) : 0;
    const paid = this.needsCap() && cap > 0 ? Math.min(computedReward, cap) : computedReward;

    const surplus =
      v.reset_policy === 'carry_over' && this.tracksAmount()
        ? Math.max(0, cycleTotal - threshold)
        : 0;

    return {
      cycleTotal: Math.round(cycleTotal * 100) / 100,
      computedReward: Math.round(computedReward * 100) / 100,
      paid: Math.round(paid * 100) / 100,
      wasCapped: computedReward > paid,
      surplus: Math.round(surplus * 100) / 100,
      carriesOver: v.reset_policy === 'carry_over',
    };
  });

  constructor() {
    this.form.controls.threshold_type.valueChanges.subscribe((type) => this.thresholdType.set(type));
    this.form.controls.reward_type.valueChanges.subscribe((type) => this.rewardType.set(type));
    this.form.valueChanges.subscribe(() => this.values.set(this.form.getRawValue()));

    this.load();
  }

  /** A superseded version is read-only history; only its dates matter at a glance. */
  thresholdLabel(rule: LoyaltyRule): string {
    if (rule.threshold_type === 'invoice_count') {
      return `${rule.threshold_invoice_count}`;
    }

    if (rule.threshold_type === 'both') {
      return `${rule.threshold_amount} + ${rule.threshold_invoice_count}`;
    }

    return `${rule.threshold_amount}`;
  }

  rewardLabel(rule: LoyaltyRule): string {
    return rule.reward_type === 'percentage'
      ? `${rule.reward_value}%`
      : `${rule.reward_value}`;
  }

  submit(): void {
    if (this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.formError.set(null);
    clearServerErrors(this.form);

    this.rules.publish(this.payload()).subscribe({
      next: () => {
        this.notifications.success('loyaltyRule.published');
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);
        this.formError.set(applyServerErrors(this.form, response)[0] ?? null);
      },
    });
  }

  /**
   * Sends only the fields the chosen types actually use. Leaving a stale ceiling on
   * a flat reward, or an amount on a visit-count threshold, would store settings
   * that never apply and confuse the next person to read them.
   */
  private payload(): LoyaltyRuleForm {
    const v = this.form.getRawValue();

    return {
      threshold_type: v.threshold_type,
      threshold_amount: this.tracksAmount() ? Number(v.threshold_amount) : null,
      threshold_invoice_count: this.tracksInvoices() ? Number(v.threshold_invoice_count) : null,

      reward_type: v.reward_type,
      reward_value: Number(v.reward_value),
      max_discount_amount: this.needsCap() ? Number(v.max_discount_amount) : null,
      min_invoice_amount: Number(v.min_invoice_amount),

      accumulation_scope: v.accumulation_scope,
      reset_policy: v.reset_policy,
      balance_validity_months: v.balance_validity_months ? Number(v.balance_validity_months) : null,
      voucher_validity_days: this.isVoucher() && v.voucher_validity_days
        ? Number(v.voucher_validity_days)
        : null,

      effective_from: this.asDate(v.effective_from),
    };
  }

  private load(): void {
    this.loading.set(true);

    this.rules.state().subscribe({
      next: ({ current, history, defaults }) => {
        this.current.set(current);
        this.history.set(history);

        // Pre-filled from the version in force, or from the defaults of BRD 11.1
        // when nothing has been published yet.
        this.form.patchValue({
          ...(current !== null ? this.toFormValues(current) : this.toFormValues(defaults)),
          // Always today: a version cannot start in the past (BR-015).
          effective_from: new Date(),
        });

        this.thresholdType.set(this.form.controls.threshold_type.value);
        this.rewardType.set(this.form.controls.reward_type.value);
        this.values.set(this.form.getRawValue());

        this.loading.set(false);
        this.saving.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.saving.set(false);
      },
    });
  }

  /** The API returns decimals as strings; the form needs numbers. */
  private toFormValues(source: LoyaltyRule | LoyaltyRuleForm): Partial<ReturnType<typeof this.form.getRawValue>> {
    const numeric = (value: string | number | null): number | null =>
      value === null || value === '' ? null : Number(value);

    return {
      threshold_type: source.threshold_type,
      threshold_amount: numeric(source.threshold_amount),
      threshold_invoice_count: source.threshold_invoice_count,
      reward_type: source.reward_type,
      reward_value: numeric(source.reward_value) ?? 0,
      max_discount_amount: numeric(source.max_discount_amount),
      min_invoice_amount: numeric(source.min_invoice_amount) ?? 0,
      accumulation_scope: source.accumulation_scope,
      reset_policy: source.reset_policy,
      balance_validity_months: source.balance_validity_months,
      voucher_validity_days: source.voucher_validity_days,
    };
  }

  private asDate(value: Date | string): string {
    const date = value instanceof Date ? value : new Date(value);

    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, '0'),
      String(date.getDate()).padStart(2, '0'),
    ].join('-');
  }
}
