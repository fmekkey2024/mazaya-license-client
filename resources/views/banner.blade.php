{{--
    Drop into your layout:  @include('license::banner')

    Shows nothing while the licence is healthy and nobody has said otherwise.

    Two things can raise it. A licence approaching expiry — visible to admins
    during the warning window, and to everyone once it has actually lapsed. And
    a message from the vendor: a suspension takes effect at their end
    immediately but only stops this system days later, when the current token
    runs out. Saying so from the first moment is both fairer than a silent
    countdown and far more likely to get the matter settled before anything
    breaks.
--}}
@php
    $licenseState  = \Mazaya\License\Facades\License::state();
    $licenseNotice = \Mazaya\License\Facades\License::vendorNotice();
    $licenseDays   = \Mazaya\License\Facades\License::daysRemaining();
    $licenseUrgent = $licenseNotice !== null || $licenseState->severity() === 'danger';
    $licenseShow   = $licenseNotice !== null || $licenseState->isWarning();
@endphp

@if ($licenseShow)
    <div role="status" data-license-notice style="
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        padding:11px 18px; font:14px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
        background:{{ $licenseUrgent ? '#fef2f2' : '#fffbeb' }};
        color:{{ $licenseUrgent ? '#991b1b' : '#92400e' }};
        border-bottom:1px solid {{ $licenseUrgent ? '#fecaca' : '#fde68a' }};">

        <strong style="font-weight:650;">
            @if ($licenseNotice !== null)
                Action needed on your subscription
            @else
                {{ $licenseState->headline() }}
            @endif
        </strong>

        @if ($licenseNotice !== null)
            {{-- The vendor's own words, rendered verbatim. --}}
            <span>{{ $licenseNotice }}</span>
        @endif

        <span style="opacity:.85;">
            @if ($licenseState === \Mazaya\License\Enums\LicenseState::Grace)
                The system is still fully usable during the grace period, and will
                become read-only once it ends.
            @elseif ($licenseDays !== null && $licenseDays >= 0)
                This system keeps working for
                {{ $licenseDays }} more {{ \Illuminate\Support\Str::plural('day', $licenseDays) }}.
            @endif
        </span>
    </div>
@endif
