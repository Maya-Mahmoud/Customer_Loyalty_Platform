@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">{{ __('Use the code below to confirm your email address.') }}</p>

    <p style="margin:0 0 16px;text-align:center;">
        <span style="display:inline-block;padding:12px 24px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;font-size:26px;font-weight:700;letter-spacing:6px;direction:ltr;">
            {{ $code }}
        </span>
    </p>

    <p style="margin:0;color:#64748b;">
        {{ __('The code expires in :minutes minutes. If you did not request it, ignore this message.', ['minutes' => $expiresInMinutes]) }}
    </p>
@endsection
