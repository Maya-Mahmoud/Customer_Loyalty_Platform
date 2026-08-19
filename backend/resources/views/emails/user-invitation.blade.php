@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">{{ __('Hello :name,', ['name' => $user->name]) }}</p>

    <p style="margin:0 0 16px;">
        {{ __('An account has been created for you. Choose your own password using the link below — we never send passwords by email.') }}
    </p>

    <p style="margin:0 0 16px;">
        <a href="{{ $invitationUrl }}"
           style="display:inline-block;padding:11px 22px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">
            {{ __('Set my password') }}
        </a>
    </p>

    <p style="margin:0;color:#64748b;">
        {{ __('The link is valid for :hours hours.', ['hours' => $expiresInHours]) }}
    </p>
@endsection
