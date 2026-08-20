import { DOCUMENT } from '@angular/common';
import { Direction } from '@angular/cdk/bidi';
import { Injectable, computed, inject, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';

import { environment } from '../../../environments/environment';

const LANG_KEY = 'clp.lang';

export type AppLanguage = 'ar' | 'en';

/**
 * Owns the active language and the writing direction that follows from it
 * (BRD NFR-07).
 *
 * The `dir` attribute on <html> drives our own logical CSS, but Angular Material
 * does not read it: its Directionality service samples the document once at
 * construction. So `direction` is exposed as a signal and bound through the CDK
 * `dir` directive in AppComponent, which is what makes Material re-lay-out when
 * the language is switched instead of only after a reload.
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly translate = inject(TranslateService);
  private readonly document = inject(DOCUMENT);

  readonly current = signal<AppLanguage>(environment.defaultLanguage as AppLanguage);

  /** Bound to the CDK `dir` directive, so Material follows a language switch. */
  readonly direction = computed<Direction>(() => (this.current() === 'ar' ? 'rtl' : 'ltr'));

  init(): void {
    const stored = localStorage.getItem(LANG_KEY) as AppLanguage | null;
    const language = this.isSupported(stored) ? stored : (environment.defaultLanguage as AppLanguage);

    this.translate.addLangs(environment.supportedLanguages);
    this.translate.setDefaultLang(environment.defaultLanguage);
    this.use(language);
  }

  use(language: AppLanguage): void {
    if (!this.isSupported(language)) {
      return;
    }

    this.current.set(language);
    this.translate.use(language);
    localStorage.setItem(LANG_KEY, language);

    // Still set on the document, because our own logical CSS and the browser's
    // text handling read it from there rather than from Angular.
    this.document.documentElement.lang = language;
    this.document.documentElement.dir = this.direction();
  }

  toggle(): void {
    this.use(this.current() === 'ar' ? 'en' : 'ar');
  }

  private isSupported(language: string | null): language is AppLanguage {
    return language !== null && environment.supportedLanguages.includes(language);
  }
}
