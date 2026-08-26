import { Component, computed, input, signal } from '@angular/core';

export interface DonutSlice {
  label: string;
  value: number;
}

interface RenderedSlice extends DonutSlice {
  path: string;
  color: string;
  share: number;
}

/**
 * A donut for composition — what a set of counts is made of.
 *
 * Written as inline SVG rather than pulled from a charting library. Two small charts
 * do not justify three hundred kilobytes on a shop's connection (BRD RSK-04), and a
 * library would arrive with its own palette to fight ours.
 *
 * The palette is fixed and assigned in order, never cycled: a slice keeps its colour
 * when the filter above changes the list, so the reader is not re-learning the chart
 * every time they narrow it. It was checked for colour-blind separation rather than
 * chosen by eye — the two commonest confusions, red/green and blue/purple, are what
 * an unvalidated palette walks straight into.
 */
@Component({
  selector: 'app-donut-chart',
  standalone: true,
  template: `
    <div class="flex flex-wrap items-center gap-5">
      <svg [attr.viewBox]="'0 0 ' + size + ' ' + size" class="shrink-0"
           [style.width.px]="size" [style.height.px]="size" role="img"
           [attr.aria-label]="ariaLabel()">
        @for (slice of slices(); track slice.label) {
          <path [attr.d]="slice.path" [attr.fill]="slice.color"
                [attr.opacity]="active() === null || active() === slice.label ? 1 : 0.35"
                (mouseenter)="active.set(slice.label)" (mouseleave)="active.set(null)"
                class="cursor-default transition-opacity">
            <!-- The native tooltip: no positioning maths, and it works in both
                 directions without being told which one it is in. -->
            <title>{{ slice.label }} — {{ slice.value }}</title>
          </path>
        }

        <!-- The hole earns its place by holding the total, which is the number the
             reader would otherwise add up themselves. -->
        <text [attr.x]="size / 2" [attr.y]="size / 2 - 2" text-anchor="middle"
              class="fill-current" style="font-size: 20px; font-weight: 700; fill: #0b2f2d">
          {{ total() }}
        </text>
        <text [attr.x]="size / 2" [attr.y]="size / 2 + 16" text-anchor="middle"
              style="font-size: 11px; fill: #6b7a78">
          {{ totalLabel() }}
        </text>
      </svg>

      <!-- A legend is not optional above one series: identity must never be carried
           by colour alone. -->
      <ul class="flex-1 min-w-[9rem] flex flex-col gap-1.5 m-0 p-0 list-none">
        @for (slice of slices(); track slice.label) {
          <li class="flex items-center gap-2 text-xs"
              (mouseenter)="active.set(slice.label)" (mouseleave)="active.set(null)">
            <span class="w-2.5 h-2.5 rounded-sm shrink-0" [style.background]="slice.color"></span>
            <span class="flex-1 truncate" style="color: #1f2d2c" dir="auto">{{ slice.label }}</span>
            <span class="tabular-nums" style="color: #6b7a78" dir="ltr">
              {{ slice.value }} · {{ slice.share }}%
            </span>
          </li>
        }
      </ul>
    </div>
  `,
})
export class DonutChartComponent {
  readonly data = input.required<DonutSlice[]>();
  readonly totalLabel = input('');

  readonly size = 160;

  private readonly stroke = 30;

  readonly active = signal<string | null>(null);

  /**
   * Validated for contrast and colour-blind separation before being written down;
   * the last slot is a neutral, because "other" is a leftover rather than a member.
   */
  private readonly palette = ['#0d9488', '#b5851c', '#9c3d6b', '#3f6fb5', '#c0562f', '#94a3a1'];

  readonly total = computed(() => this.data().reduce((sum, slice) => sum + slice.value, 0));

  readonly ariaLabel = computed(() =>
    this.data()
      .map((slice) => `${slice.label}: ${slice.value}`)
      .join(', ')
  );

  readonly slices = computed<RenderedSlice[]>(() => {
    const data = this.data().filter((slice) => slice.value > 0);
    const total = data.reduce((sum, slice) => sum + slice.value, 0);

    if (total === 0) {
      return [];
    }

    const centre = this.size / 2;
    const radius = centre - this.stroke / 2;
    let angle = -Math.PI / 2;

    return data.map((slice, index) => {
      const sweep = (slice.value / total) * Math.PI * 2;
      /*
       * A hairline of surface between segments. Without it two adjacent fills touch
       * and read as one shape, which is the whole failure mode of a donut.
       */
      const gap = data.length > 1 ? 0.02 : 0;
      const from = angle + gap / 2;
      const to = angle + sweep - gap / 2;

      angle += sweep;

      return {
        ...slice,
        share: Math.round((slice.value / total) * 100),
        color: this.palette[index % this.palette.length],
        path: this.arc(centre, radius, from, Math.max(from + 0.001, to)),
      };
    });
  });

  /** An annular sector: out along one edge, round the rim, back along the other. */
  private arc(centre: number, radius: number, from: number, to: number): string {
    const inner = radius - this.stroke;
    const large = to - from > Math.PI ? 1 : 0;

    const point = (r: number, a: number) =>
      `${(centre + r * Math.cos(a)).toFixed(2)} ${(centre + r * Math.sin(a)).toFixed(2)}`;

    // A full circle has no seam to draw, so it is split into two halves instead.
    if (to - from >= Math.PI * 2 - 0.001) {
      const half = from + Math.PI;

      return (
        `M ${point(radius, from)} A ${radius} ${radius} 0 1 1 ${point(radius, half)}` +
        ` A ${radius} ${radius} 0 1 1 ${point(radius, from)} Z` +
        ` M ${point(inner, from)} A ${inner} ${inner} 0 1 0 ${point(inner, half)}` +
        ` A ${inner} ${inner} 0 1 0 ${point(inner, from)} Z`
      );
    }

    return (
      `M ${point(radius, from)}` +
      ` A ${radius} ${radius} 0 ${large} 1 ${point(radius, to)}` +
      ` L ${point(inner, to)}` +
      ` A ${inner} ${inner} 0 ${large} 0 ${point(inner, from)} Z`
    );
  }
}
