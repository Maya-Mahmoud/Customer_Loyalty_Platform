import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';

import { environment } from '../../../environments/environment';

const LANG_KEY = 'clp.lang';

export type AppLanguage = 'ar' | 'en';

/**
 * Owns the active language and keeps the document direction in sync, which is
 * what Angular Material and our own utility classes read for RTL (NFR-07).
 */
@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly translate = inject(TranslateService);
  private readonly document = inject(DOCUMENT);

  readonly current = signal<AppLanguage>(environment.defaultLanguage as AppLanguage);

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

    const direction = language === 'ar' ? 'rtl' : 'ltr';
    this.document.documentElement.lang = language;
    this.document.documentElement.dir = direction;
  }

  toggle(): void {
    this.use(this.current() === 'ar' ? 'en' : 'ar');
  }

  get direction(): 'rtl' | 'ltr' {
    return this.current() === 'ar' ? 'rtl' : 'ltr';
  }

  private isSupported(language: string | null): language is AppLanguage {
    return language !== null && environment.supportedLanguages.includes(language);
  }
}
