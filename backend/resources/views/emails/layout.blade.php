{{-- Shared shell for every outgoing email. Direction follows the app locale so
     Arabic messages read correctly (BRD NFR-07). --}}
@php
    $rtl = app()->getLocale() === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#1e293b;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
        <div style="padding:16px 24px;background:#1e293b;color:#ffffff;font-size:15px;font-weight:600;">
            {{ config('app.name') }}
        </div>

        <div style="padding:24px;font-size:14px;line-height:1.7;">
            @yield('body')
        </div>

        <div style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
            {{ __('This is an automated message; please do not reply to it.') }}
        </div>
    </div>
</body>
</html>
