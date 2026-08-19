@extends('emails.layout')

@section('body')
    <p style="margin:0 0 12px;">{{ __('Hello :name,', ['name' => $merchant->owner_name]) }}</p>

    @if ($status === App\Enums\MerchantStatus::Active)
        <p style="margin:0 0 12px;">
            {{ __('Your store :merchant has been activated. You can now sign in with the email and password you chose when registering.', ['merchant' => $merchant->name]) }}
        </p>
    @elseif ($status === App\Enums\MerchantStatus::Rejected)
        <p style="margin:0 0 12px;">
            {{ __('Your registration request for :merchant was not approved.', ['merchant' => $merchant->name]) }}
        </p>
        <p style="margin:0 0 12px;">{{ __('You may correct the details and apply again.') }}</p>
    @elseif ($status === App\Enums\MerchantStatus::Suspended)
        <p style="margin:0 0 12px;">
            {{ __('Access to :merchant has been suspended. Your customer data is retained and nothing has been deleted.', ['merchant' => $merchant->name]) }}
        </p>
    @endif

    @if ($reason)
        <div style="margin:0 0 12px;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;">
            <strong style="display:block;margin-bottom:4px;">{{ __('Reason') }}</strong>
            {{ $reason }}
        </div>
    @endif

    <p style="margin:0;color:#64748b;">{{ __('For any question, contact platform support.') }}</p>
@endsection
