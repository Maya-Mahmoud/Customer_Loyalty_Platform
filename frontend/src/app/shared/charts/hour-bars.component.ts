import { Component, computed, input, signal } from '@angular/core';

export interface HourBucket {
  hour: number;
  total: number;
}

interface RenderedBar extends HourBucket {
  x: number;
  y: number;
  width: number;
  height: number;
}

/**
 * Activity by hour of day.
 *
 * One series, one hue: the bars measure the same thing at different times, and
 * colouring the tallest one differently would be colouring by rank rather than by
 * what the bar is. The peak is called out in words underneath instead, which also
 * survives being printed in black and white.
 *
 * Drawn left to right in both languages. An hour axis is a number line, not prose —
 * mirroring it in Arabic would put midnight on the right and make every reader
 * translate the shape before reading it.
 */
@Component({
  selector: 'app-hour-bars',
  standalone: true,
  template: `
    <div dir="ltr">
      <svg [attr.viewBox]="'0 0 ' + width + ' ' + height" class="w-full"
           [style.height.px]="height" role="img" [attr.aria-label]="ariaLabel()">
        <!-- Two recessive rules, not a grid: enough to judge a height against, not
             enough to compete with the bars. -->
        @for (line of gridLines(); track line.value) {
          <line [attr.x1]="padding.left" [attr.x2]="width - padding.right"
                [attr.y1]="line.y" [attr.y2]="line.y" stroke="#e9e6e0" stroke-width="1" />
          <text [attr.x]="padding.left - 6" [attr.y]="line.y + 3" text-anchor="end"
                style="font-size: 9px; fill: #9aa8a6">{{ line.value }}</text>
        }

        @for (bar of bars(); track bar.hour) {
          <rect [attr.x]="bar.x" [attr.y]="bar.y" [attr.width]="bar.width"
                [attr.height]="bar.height" rx="3" fill="#1d6660"
                [attr.opacity]="active() === null || active() === bar.hour ? 1 : 0.4"
                (mouseenter)="active.set(bar.hour)" (mouseleave)="active.set(null)">
            <title>{{ label(bar.hour) }} — {{ bar.total }}</title>
          </rect>
        }

        <!-- Every third hour: a label under all twenty-four collides at this width,
             and a collided label is worse than an absent one. -->
        @for (bar of bars(); track bar.hour) {
          @if (bar.hour % 3 === 0) {
            <text [attr.x]="bar.x + bar.width / 2" [attr.y]="height - 4" text-anchor="middle"
                  style="font-size: 9px; fill: #9aa8a6">{{ bar.hour }}</text>
          }
        }
      </svg>
    </div>
  `,
})
export class HourBarsComponent {
  readonly data = input.required<HourBucket[]>();

  readonly width = 300;
  readonly height = 110;
  readonly padding = { top: 8, right: 4, bottom: 16, left: 20 };

  readonly active = signal<number | null>(null);

  readonly max = computed(() => Math.max(1, ...this.data().map((bucket) => bucket.total)));

  readonly ariaLabel = computed(() =>
    this.data()
      .filter((bucket) => bucket.total > 0)
      .map((bucket) => `${bucket.hour}:00 — ${bucket.total}`)
      .join(', ')
  );

  /** The busiest hour, named in words so the chart is not the only way to read it. */
  readonly peak = computed(() => {
    const busiest = [...this.data()].sort((a, b) => b.total - a.total)[0];

    return busiest && busiest.total > 0 ? busiest : null;
  });

  readonly gridLines = computed(() => {
    const max = this.max();
    const plot = this.height - this.padding.top - this.padding.bottom;

    return [max, Math.round(max / 2)]
      .filter((value, index, all) => value > 0 && all.indexOf(value) === index)
      .map((value) => ({
        value,
        y: this.padding.top + plot - (value / max) * plot,
      }));
  });

  readonly bars = computed<RenderedBar[]>(() => {
    const data = this.data();
    const max = this.max();
    const plot = this.height - this.padding.top - this.padding.bottom;
    const usable = this.width - this.padding.left - this.padding.right;
    const step = usable / data.length;
    // A 2px channel of surface between neighbours keeps them separate shapes.
    const barWidth = Math.max(3, step - 2);

    return data.map((bucket, index) => {
      const barHeight = bucket.total === 0 ? 0 : Math.max(2, (bucket.total / max) * plot);

      return {
        ...bucket,
        x: this.padding.left + index * step + (step - barWidth) / 2,
        y: this.padding.top + plot - barHeight,
        width: barWidth,
        height: barHeight,
      };
    });
  });

  label(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
  }
}
