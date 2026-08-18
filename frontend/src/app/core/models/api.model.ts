/**
 * Shape returned by the Laravel exception handler for every failed request.
 */
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

/**
 * Laravel's length-aware paginator, as consumed by the reports and list screens.
 */
export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
