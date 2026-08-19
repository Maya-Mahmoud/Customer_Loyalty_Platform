@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">
        {{ __('The subscription for :merchant expires in :days days, on :date.', [
            'merchant' => $merchant->name,
            'days' => $daysRemaining,
            'date' => $merchant->subscription_ends_at?->toDateString(),
        ]) }}
    </p>

    <p style="margin:0;color:#64748b;">
        {{ __('Renew before that date to avoid the account being suspended.') }}
    </p>
@endsection
