import { Component, computed, inject, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';

import { PlatformBrandService } from '../core/services/platform-brand.service';

/**
 * The platform's mark, wherever the platform speaks for itself.
 *
 * One component rather than an image tag repeated on seven screens, because the
 * fallback is the part that repeats: until a supervisor uploads a logo the platform
 * still needs a mark, and every screen would otherwise carry its own copy of the
 * gold badge and its own idea of when to show it.
 *
 * Deliberately not used in a shop's own sidebar. That space carries the shop's logo
 * or the shop's initials — it belongs to the merchant, and putting our mark in it
 * would be taking their shelf.
 */
@Component({
  selector: 'clp-platform-logo',
  standalone: true,
  imports: [MatIconModule],
  template: `
    @if (brand.logoUrl(); as url) {
      <img
        [src]="url"
        alt=""
        class="rounded-xl object-contain bg-white shrink-0 shadow-sm"
        [style.width.px]="size()"
        [style.height.px]="size()"
        [style.padding.px]="padding()"
      />
    } @else {
      <span
        class="rounded-xl flex items-center justify-center shrink-0"
        [style.width.px]="size()"
        [style.height.px]="size()"
        style="background: linear-gradient(135deg, #d9a747, #c28f2c); color: #0b2f2d"
      >
        <mat-icon
          [style.width.px]="glyph()"
          [style.height.px]="glyph()"
          [style.fontSize.px]="glyph()"
          [style.lineHeight.px]="glyph()"
          >loyalty</mat-icon
        >
      </span>
    }
  `,
})
export class PlatformLogoComponent {
  readonly brand = inject(PlatformBrandService);

  /** The side of the square, in pixels. The mark sits in headers of very different
      weights — a 36px bar, an 80px sidebar panel — so the caller decides. */
  readonly size = input(36);

  /** An uploaded logo is padded off its white tile so a square image does not read
      as a sticker; the drawn badge fills its own box and needs none. */
  readonly padding = computed(() => Math.max(2, Math.round(this.size() * 0.1)));

  readonly glyph = computed(() => Math.round(this.size() * 0.56));

  constructor() {
    this.brand.load();
  }
}
