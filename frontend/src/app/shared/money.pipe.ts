import { Pipe, PipeTransform, inject } from '@angular/core';

import { LanguageService } from '../core/services/language.service';

/**
 * A money figure, grouped.
 *
 * Amounts arrive from the API as plain decimal strings and were printed as they
 * came: "10168000.00". Nobody reads that — the eye has to count digits to find out
 * whether a shop took ten million or one. Grouping is the whole of the fix, and it
 * belongs in one place because every screen in the application shows money.
 *
 * The decimals are dropped above a thousand. On a dashboard tile the two trailing
 * zeros are noise competing with the digits that carry the meaning; below a
 * thousand they can still matter, so they stay.
 */
@Pipe({ name: 'money', standalone: true, pure: true })
export class MoneyPipe implements PipeTransform {
  private readonly language = inject(LanguageService);

  transform(value: number | string | null | undefined): string {
    const amount = this.toNumber(value);

    if (amount === null) {
      return '—';
    }

    const decimals = Math.abs(amount) >= 1000 || Number.isInteger(amount) ? 0 : 2;

    return new Intl.NumberFormat(this.locale(), {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(amount);
  }

  private toNumber(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
      return null;
    }

    const amount = typeof value === 'string' ? Number(value) : value;

    return Number.isFinite(amount) ? amount : null;
  }

  /*
   * Latin digits in both languages, deliberately. Arabic-Indic digits are correct
   * Arabic, but a shop's own till, its printed receipts and every price tag in the
   * country use Latin ones, and a figure that does not match the receipt beside it
   * is a figure the reader stops to reconcile.
   */
  private locale(): string {
    return this.language.current() === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB';
  }
}

/**
 * The same figure said out loud: "10.2 مليون".
 *
 * Printed under the grouped number rather than instead of it. Grouping makes a
 * figure countable; this makes it graspable without counting at all, which is what
 * somebody deciding whether to buy actually does with it.
 *
 * Only worth showing from ten thousand up — below that the digits are already a
 * single glance and a second line would be clutter.
 */
@Pipe({ name: 'moneyWords', standalone: true, pure: true })
export class MoneyWordsPipe implements PipeTransform {
  private readonly language = inject(LanguageService);

  transform(value: number | string | null | undefined): string | null {
    const amount = typeof value === 'string' ? Number(value) : value;

    if (amount === null || amount === undefined || !Number.isFinite(amount)) {
      return null;
    }

    const size = Math.abs(amount);

    if (size < 10_000) {
      return null;
    }

    const arabic = this.language.current() === 'ar';

    const scales: Array<{ at: number; ar: string; en: string }> = [
      { at: 1_000_000_000, ar: 'مليار', en: 'billion' },
      { at: 1_000_000, ar: 'مليون', en: 'million' },
      { at: 1_000, ar: 'ألف', en: 'thousand' },
    ];

    for (const scale of scales) {
      if (size >= scale.at) {
        const short = amount / scale.at;

        // One decimal place, and not even that when it would read ".0".
        const figure = Number.isInteger(short) ? String(short) : short.toFixed(1);

        return `${figure} ${arabic ? scale.ar : scale.en}`;
      }
    }

    return null;
  }
}
