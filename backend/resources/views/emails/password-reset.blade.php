@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">{{ __('Hello :name,', ['name' => $user->name]) }}</p>

    <p style="margin:0 0 16px;">
        {{ __('Use the code below to choose a new password.') }}
    </p>

    <p style="margin:0 0 16px;text-align:center;">
        <span style="display:inline-block;padding:12px 24px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;font-size:26px;font-weight:700;letter-spacing:6px;direction:ltr;">
            {{ $code }}
        </span>
    </p>

    <p style="margin:0;color:#64748b;">
        {{ __('The code expires in :minutes minutes. If you did not request this, ignore this message and your password stays unchanged.', ['minutes' => $expiresInMinutes]) }}
    </p>
@endsection
