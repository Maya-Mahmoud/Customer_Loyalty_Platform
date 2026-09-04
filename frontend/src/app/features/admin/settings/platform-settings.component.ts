import { Component, inject, signal } from '@angular/core';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslateModule } from '@ngx-translate/core';

import { PlatformSettings, SubscriptionPlan } from '../../../core/models/merchant.model';
import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import { NotificationService } from '../../../core/services/notification.service';
import { PlatformSettingsService } from '../../../core/services/platform-settings.service';

/** One plan and the form that prices it, kept together so the screen can render a
    row without looking anything up. */
interface PlanRow {
  plan: SubscriptionPlan;
  form: FormGroup<{
    monthly_price: FormControl<number>;
    max_branches: FormControl<number | null>;
    max_users: FormControl<number | null>;
    max_monthly_invoices: FormControl<number | null>;
  }>;
}

/**
 * The platform's own settings (BRD FR-ADM-04): the money it bills in, and what each
 * plan costs and allows.
 *
 * Deliberately not the same screen as a store's settings. That one belongs to a shop
 * owner and describes their shop; this one belongs to the supervisor and describes
 * the platform, and the only thing they have in common is the word "settings".
 *
 * Each plan saves on its own. A single save button over the whole price list would
 * mean a mistyped figure in one row rejects the correct figures in the others, and
 * the supervisor would not be able to tell which row the error belonged to.
 */
@Component({
  selector: 'app-platform-settings',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    MatTooltipModule,
  ],
  templateUrl: './platform-settings.component.html',
})
export class PlatformSettingsComponent {
  private readonly fb = inject(FormBuilder);
  private readonly settings = inject(PlatformSettingsService);
  private readonly notifications = inject(NotificationService);

  readonly loading = signal(true);
  readonly savingCurrency = signal(false);
  /** The id of the plan currently being saved, so one row's spinner does not
      disable the whole list. */
  readonly savingPlan = signal<number | null>(null);

  readonly currencies = signal<string[]>([]);
  readonly rows = signal<PlanRow[]>([]);

  readonly currencyForm = this.fb.nonNullable.group({
    billing_currency: ['', [Validators.required]],
  });

  /** Whether the "add a plan" form is open. Closed by default: the price list is
      what this screen is for, and a blank form above it would compete with it. */
  readonly adding = signal(false);
  readonly savingNew = signal(false);

  readonly newPlanForm = this.fb.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]{1,40}$/)]],
    name: ['', [Validators.required, Validators.maxLength(120)]],
    monthly_price: [0, [Validators.required, Validators.min(0)]],
    max_branches: this.fb.control<number | null>(null, { validators: [Validators.min(1)] }),
    max_users: this.fb.control<number | null>(null, { validators: [Validators.min(1)] }),
    max_monthly_invoices: this.fb.control<number | null>(null, {
      validators: [Validators.min(1)],
    }),
  });

  constructor() {
    this.settings.get().subscribe({
      next: (settings) => this.fill(settings),
      error: () => this.loading.set(false),
    });
  }

  saveCurrency(): void {
    if (this.currencyForm.invalid || this.savingCurrency()) {
      return;
    }

    this.savingCurrency.set(true);
    clearServerErrors(this.currencyForm);

    this.settings
      .setBillingCurrency(this.currencyForm.getRawValue().billing_currency)
      .subscribe({
        next: (settings) => {
          // Refilled from the response rather than assumed: the prices are shown
          // against this currency, so the two must move at the same moment.
          this.fill(settings);
          this.notifications.success('platformSettings.currencySaved');
          this.savingCurrency.set(false);
        },
        error: (error) => {
          applyServerErrors(this.currencyForm, error);
          this.savingCurrency.set(false);
        },
      });
  }

  savePlan(row: PlanRow): void {
    if (row.form.invalid || this.savingPlan() !== null) {
      row.form.markAllAsTouched();
      return;
    }

    this.savingPlan.set(row.plan.id);
    clearServerErrors(row.form);

    const raw = row.form.getRawValue();

    this.settings
      .savePlan(row.plan.id, {
        monthly_price: Number(raw.monthly_price),
        // An empty field is the unlimited tier of FR-ADM-04, not a missing value.
        max_branches: this.cap(raw.max_branches),
        max_users: this.cap(raw.max_users),
        max_monthly_invoices: this.cap(raw.max_monthly_invoices),
      })
      .subscribe({
        next: (plan) => {
          this.rows.update((rows) =>
            rows.map((existing) =>
              existing.plan.id === plan.id ? { plan, form: existing.form } : existing
            )
          );
          this.notifications.success('platformSettings.planSaved');
          this.savingPlan.set(null);
        },
        error: (error) => {
          applyServerErrors(row.form, error);
          this.savingPlan.set(null);
        },
      });
  }

  toggleAdding(): void {
    this.adding.update((open) => !open);

    if (!this.adding()) {
      this.newPlanForm.reset({ code: '', name: '', monthly_price: 0 });
    }
  }

  addPlan(): void {
    if (this.newPlanForm.invalid || this.savingNew()) {
      this.newPlanForm.markAllAsTouched();
      return;
    }

    this.savingNew.set(true);
    clearServerErrors(this.newPlanForm);

    const raw = this.newPlanForm.getRawValue();

    this.settings
      .addPlan({
        code: raw.code.trim().toLowerCase(),
        name: raw.name.trim(),
        monthly_price: Number(raw.monthly_price),
        max_branches: this.cap(raw.max_branches),
        max_users: this.cap(raw.max_users),
        max_monthly_invoices: this.cap(raw.max_monthly_invoices),
      })
      .subscribe({
        next: () => {
          this.notifications.success('platformSettings.planAdded');
          this.savingNew.set(false);
          this.adding.set(false);
          this.newPlanForm.reset({ code: '', name: '', monthly_price: 0 });

          /*
           * Re-read rather than pushed onto the list: the plans are ordered by price
           * on the server, and a new plan appended to the end would sit out of order
           * until the next visit.
           */
          this.settings.get().subscribe({ next: (settings) => this.fill(settings) });
        },
        error: (error) => {
          applyServerErrors(this.newPlanForm, error);
          this.savingNew.set(false);
        },
      });
  }

  /**
   * Takes a plan off the price list, or puts it back.
   *
   * Never a delete: shops point at a plan by foreign key and their subscription
   * history refers to it, so the only safe way to stop selling one is to stop
   * offering it. It leaves registration and stays here, still correctable.
   */
  toggleActive(row: PlanRow): void {
    if (this.savingPlan() !== null) {
      return;
    }

    this.savingPlan.set(row.plan.id);

    const raw = row.form.getRawValue();

    this.settings
      .savePlan(row.plan.id, {
        monthly_price: Number(raw.monthly_price),
        max_branches: this.cap(raw.max_branches),
        max_users: this.cap(raw.max_users),
        max_monthly_invoices: this.cap(raw.max_monthly_invoices),
        is_active: !row.plan.is_active,
      })
      .subscribe({
        next: (plan) => {
          this.rows.update((rows) =>
            rows.map((existing) =>
              existing.plan.id === plan.id ? { plan, form: existing.form } : existing
            )
          );
          this.notifications.success(
            plan.is_active ? 'platformSettings.planResumed' : 'platformSettings.planRetired'
          );
          this.savingPlan.set(null);
        },
        error: () => this.savingPlan.set(null),
      });
  }

  private fill(settings: PlatformSettings): void {
    this.currencies.set(settings.currencies);
    this.currencyForm.patchValue({ billing_currency: settings.billing_currency });

    this.rows.set(
      settings.plans.map((plan) => ({
        plan,
        form: this.fb.nonNullable.group({
          monthly_price: [
            Number(plan.monthly_price),
            [Validators.required, Validators.min(0)],
          ],
          // Nullable on purpose: clearing the box is how a cap is lifted.
          max_branches: this.fb.control<number | null>(plan.max_branches, {
            validators: [Validators.min(1)],
          }),
          max_users: this.fb.control<number | null>(plan.max_users, {
            validators: [Validators.min(1)],
          }),
          max_monthly_invoices: this.fb.control<number | null>(plan.max_monthly_invoices, {
            validators: [Validators.min(1)],
          }),
        }),
      }))
    );

    this.loading.set(false);
  }

  /** An empty box means unlimited; a number means a ceiling. */
  private cap(value: number | null): number | null {
    return value === null || (value as unknown as string) === '' ? null : Number(value);
  }
}
