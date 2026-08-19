import { HttpErrorResponse } from '@angular/common/http';
import { AbstractControl, FormGroup } from '@angular/forms';

import { ApiError } from '../models/api.model';

/**
 * Copies a 422 response onto the form controls it belongs to.
 *
 * The error interceptor deliberately stays silent on 422 because validation
 * messages belong beside the offending field. This is the piece that puts them
 * there, and returns whatever could not be matched to a control so the caller can
 * show it as a form-level message instead of losing it.
 */
export function applyServerErrors(form: FormGroup, response: HttpErrorResponse): string[] {
  const body = response.error as ApiError | null;
  const errors = body?.errors;

  if (!errors) {
    return body?.message ? [body.message] : [];
  }

  const unmatched: string[] = [];

  Object.entries(errors).forEach(([field, messages]) => {
    const control: AbstractControl | null = form.get(field);
    const message = messages[0];

    if (control) {
      control.setErrors({ ...(control.errors ?? {}), server: message });
      control.markAsTouched();
    } else {
      unmatched.push(message);
    }
  });

  return unmatched;
}

/**
 * Server errors must not survive the next edit, otherwise a corrected field keeps
 * showing the old complaint.
 */
export function clearServerErrors(form: FormGroup): void {
  Object.values(form.controls).forEach((control) => {
    if (!control.hasError('server')) {
      return;
    }

    const { server: _server, ...rest } = control.errors ?? {};

    control.setErrors(Object.keys(rest).length > 0 ? rest : null);
  });
}
