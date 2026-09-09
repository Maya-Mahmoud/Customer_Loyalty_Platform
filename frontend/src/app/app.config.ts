import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { APP_INITIALIZER, ApplicationConfig, importProvidersFrom } from '@angular/core';
import { provideNativeDateAdapter } from '@angular/material/core';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { TranslateLoader, TranslateModule } from '@ngx-translate/core';
import { TranslateHttpLoader } from '@ngx-translate/http-loader';

import { routes } from './app.routes';
import { TranslatedPaginatorIntl } from './core/i18n/translated-paginator-intl';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { errorInterceptor } from './core/interceptors/error.interceptor';
import { LanguageService } from './core/services/language.service';
import { environment } from '../environments/environment';

export function translateLoaderFactory(http: HttpClient): TranslateLoader {
  return new TranslateHttpLoader(http, './assets/i18n/', '.json');
}

export const appConfig: ApplicationConfig = {
  providers: [
    provideRouter(routes, withComponentInputBinding()),
    provideAnimationsAsync(),
    // Required by the subscription end-date picker on the supervisor console.
    provideNativeDateAdapter(),
    provideHttpClient(withInterceptors([authInterceptor, errorInterceptor])),
    importProvidersFrom(
      TranslateModule.forRoot({
        defaultLanguage: environment.defaultLanguage,
        loader: {
          provide: TranslateLoader,
          useFactory: translateLoaderFactory,
          deps: [HttpClient],
        },
      })
    ),
    // Material keeps the paginator's words inside the component, so the only way
    // to have them in Arabic is to replace the service that supplies them.
    { provide: MatPaginatorIntl, useClass: TranslatedPaginatorIntl },
    {
      // Resolves the stored language and sets <html dir> before the first
      // render, so the app never flashes in the wrong direction.
      provide: APP_INITIALIZER,
      multi: true,
      deps: [LanguageService],
      useFactory: (language: LanguageService) => () => language.init(),
    },
  ],
};
