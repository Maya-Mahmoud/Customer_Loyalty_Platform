import { BidiModule } from '@angular/cdk/bidi';
import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { LanguageService } from './core/services/language.service';

/**
 * Nothing but the router outlet inside a direction wrapper. The authenticated
 * chrome lives in ShellComponent, and the login screen deliberately has none.
 *
 * The `dir` binding is what lets Angular Material re-lay-out when the language is
 * switched. Its Directionality service samples the document once at construction,
 * so setting the attribute on <html> alone leaves components — the side navigation
 * most visibly — computing their offsets for the previous direction.
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, BidiModule],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
})
export class AppComponent {
  private readonly language = inject(LanguageService);

  readonly direction = this.language.direction;
}
