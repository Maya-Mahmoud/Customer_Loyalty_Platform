import { Component, computed, input } from '@angular/core';

export interface BarDatum {
  /** What the bar is: a month, a branch, a person. */
  label: string;
  value: number;
  /** Printed at the end of the bar. Already formatted — the chart does no maths. */
  display?: string;
}

interface Rendered extends BarDatum {
  width: number;
  share: number;
}

/**
 * A bar per row, labelled, sorted by whatever the caller sorted.
 *
 * Bars rather than a line or a pie, and horizontal rather than vertical, because
 * every series this screen has is a short list of named things — nine months, three
 * branches, ten people — and a named thing needs its name beside it. A horizontal
 * bar gives the label a whole line to sit on and never truncates "فرع الأزهري" into
 * "فرع الأز…", which is what a column chart does to the same data.
 *
 * Built from divs, not SVG. The widths are percentages of the largest value, so the
 * browser does the arithmetic the layout would otherwise have to be told, and the
 * text inside stays selectable and translatable.
 *
 * Bars grow from the start edge, so they run right-to-left in Arabic along with
 * everything else on the page. A magnitude has no inherent direction — unlike the
 * hour axis next door, which is a number line and stays left to right in both.
 */
@Component({
  selector: 'app-bar-chart',
  standalone: true,
  template: `
    @if (rows().length === 0) {
      <p class="text-sm text-slate-500 py-4">{{ emptyText() }}</p>
    } @else {
      <div class="flex flex-col gap-2.5">
        @for (row of rows(); track row.label) {
          <div>
            <div class="flex items-baseline justify-between gap-3 mb-1">
              <span class="text-xs font-medium truncate" style="color: var(--clp-ink)">
                {{ row.label }}
              </span>
              <span class="text-xs text-slate-600 shrink-0" dir="ltr">
                {{ row.display ?? row.value }}
              </span>
            </div>

            <div class="h-2 rounded-full overflow-hidden" style="background: #e4eceb">
              <!--
                A minimum of two per cent so a real but tiny value is still a mark on
                the page. A bar of zero width and a bar that is genuinely absent look
                the same, and only one of them is true.
              -->
              <div
                class="h-full rounded-full"
                [style.width.%]="row.width"
                [style.background]="colour()"
              ></div>
            </div>
          </div>
        }
      </div>
    }
  `,
})
export class BarChartComponent {
  readonly data = input<BarDatum[]>([]);

  readonly colour = input('#1d6660');

  readonly emptyText = input('—');

  readonly rows = computed<Rendered[]>(() => {
    const data = this.data();
    const largest = Math.max(...data.map((d) => d.value), 0);

    return data.map((d) => {
      const share = largest > 0 ? d.value / largest : 0;

      return { ...d, share, width: d.value > 0 ? Math.max(2, share * 100) : 0 };
    });
  });
}
