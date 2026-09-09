import { Injectable, inject } from '@angular/core';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { TranslateService } from '@ngx-translate/core';

/**
 * The table paginator's own words, in the language the rest of the screen is in.
 *
 * Material ships these strings in English inside the component and offers no way to
 * translate them from a template, so "Items per page" and "1 – 5 of 5" sat in Latin
 * script at the bottom of an otherwise Arabic table. Overriding the intl service is
 * the supported way in, and it is registered once in app.config so every paginator
 * added later is translated without anybody remembering to.
 *
 * The range label is built with an explicit start–end rather than Material's
 * default, because the default reads "of" in the middle of an Arabic sentence and
 * the numerals have to keep their own direction inside it.
 */
@Injectable()
export class TranslatedPaginatorIntl extends MatPaginatorIntl {
  private readonly translate = inject(TranslateService);

  constructor() {
    super();

    // Re-read on a language switch, and tell every live paginator to repaint.
    this.translate.onLangChange.subscribe(() => {
      this.apply();
      this.changes.next();
    });

    this.apply();
  }

  override getRangeLabel = (page: number, pageSize: number, length: number): string => {
    if (length === 0 || pageSize === 0) {
      return this.translate.instant('paginator.empty');
    }

    const start = page * pageSize + 1;
    const end = Math.min(start + pageSize - 1, length);

    return this.translate.instant('paginator.range', { start, end, total: length });
  };

  private apply(): void {
    this.itemsPerPageLabel = this.translate.instant('paginator.itemsPerPage');
    this.nextPageLabel = this.translate.instant('paginator.next');
    this.previousPageLabel = this.translate.instant('paginator.previous');
    this.firstPageLabel = this.translate.instant('paginator.first');
    this.lastPageLabel = this.translate.instant('paginator.last');
  }
}
