@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">{{ __('A new registration request is waiting for review.') }}</p>

    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('Store') }}</td>
            <td style="padding:6px 0;font-weight:600;">{{ $merchant->name }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('Commercial register') }}</td>
            <td style="padding:6px 0;" dir="ltr">{{ $merchant->commercial_register }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('Owner') }}</td>
            <td style="padding:6px 0;">{{ $merchant->owner_name }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('Email') }}</td>
            <td style="padding:6px 0;" dir="ltr">{{ $merchant->email }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('Phone') }}</td>
            <td style="padding:6px 0;" dir="ltr">{{ $merchant->phone }}</td>
        </tr>
        <tr>
            <td style="padding:6px 0;color:#64748b;">{{ __('City') }}</td>
            <td style="padding:6px 0;">{{ $merchant->city }}</td>
        </tr>
    </table>

    <p style="margin:16px 0 0;color:#64748b;">
        {{ __('Both the email address and the phone number have been verified.') }}
    </p>
@endsection
