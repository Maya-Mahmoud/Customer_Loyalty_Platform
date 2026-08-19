import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { debounceTime, distinctUntilChanged } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { MerchantStatus } from '../../../core/models/auth.model';
import { AdminMerchant, PlatformStats } from '../../../core/models/merchant.model';
import { AdminMerchantService } from '../../../core/services/admin-merchant.service';

/**
 * The registration queue of BRD FR-ADM-01.
 *
 * Pending requests are listed first by the API, because working through them is
 * the reason this screen exists.
 */
@Component({
  selector: 'app-merchant-list',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressSpinnerModule,
    MatTableModule,
    MatTooltipModule,
  ],
  templateUrl: './merchant-list.component.html',
})
export class MerchantListComponent {
  private readonly merchants = inject(AdminMerchantService);
  private readonly fb = inject(FormBuilder);

  readonly columns = ['name', 'city', 'status', 'submitted', 'counts', 'actions'];
  readonly statuses: (MerchantStatus | '')[] = ['', 'pending', 'active', 'suspended', 'rejected'];

  readonly rows = signal<AdminMerchant[]>([]);
  readonly stats = signal<PlatformStats | null>(null);
  readonly loading = signal(true);
  readonly total = signal(0);
  readonly pageIndex = signal(0);
  readonly pageSize = signal(20);
  readonly activeStatus = signal<MerchantStatus | ''>('');

  readonly searchControl = this.fb.nonNullable.control('');

  constructor() {
    this.searchControl.valueChanges
      .pipe(debounceTime(350), distinctUntilChanged(), takeUntilDestroyed())
      .subscribe(() => {
        this.pageIndex.set(0);
        this.load();
      });

    this.load();
    this.loadStats();
  }

  filterBy(status: MerchantStatus | ''): void {
    this.activeStatus.set(status);
    this.pageIndex.set(0);
    this.load();
  }

  onPage(event: PageEvent): void {
    this.pageIndex.set(event.pageIndex);
    this.pageSize.set(event.pageSize);
    this.load();
  }

  /** Colour-codes the status chip so the queue is scannable at a glance. */
  statusClass(status: MerchantStatus): string {
    return {
      pending: 'bg-amber-100 text-amber-900',
      active: 'bg-emerald-100 text-emerald-900',
      suspended: 'bg-orange-100 text-orange-900',
      rejected: 'bg-red-100 text-red-900',
    }[status];
  }

  private load(): void {
    this.loading.set(true);

    this.merchants
      .list({
        status: this.activeStatus(),
        search: this.searchControl.value,
        page: this.pageIndex() + 1,
        per_page: this.pageSize(),
      })
      .subscribe({
        next: (page) => {
          this.rows.set(page.data);
          this.total.set(page.meta.total);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  private loadStats(): void {
    this.merchants.stats().subscribe({
      next: (stats) => this.stats.set(stats),
      error: () => this.stats.set(null),
    });
  }
}
