{{--
    Drop into your layout:  @include('license::banner')

    Shows nothing while the license is healthy. During the warning window it is
    visible to administrators only; once the license has actually expired it is
    shown to everyone, because by then it affects everyone's work.
--}}
@php
    $licenseState = \Mazaya\License\Facades\License::state();
    $licenseDays  = \Mazaya\License\Facades\License::daysRemaining();
@endphp

@if ($licenseState->isWarning() && ($showToEveryone ?? \Mazaya\License\Facades\License::shouldWarnEveryone() || ($isAdmin ?? true)))
    <div role="status" style="
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        padding:11px 18px; font:14px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
        background:{{ $licenseState->severity() === 'danger' ? '#fef2f2' : '#fffbeb' }};
        color:{{ $licenseState->severity() === 'danger' ? '#991b1b' : '#92400e' }};
        border-bottom:1px solid {{ $licenseState->severity() === 'danger' ? '#fecaca' : '#fde68a' }};">

        <strong style="font-weight:650;">{{ $licenseState->headline() }}</strong>

        <span style="opacity:.85;">
            @if ($licenseState === \Mazaya\License\Enums\LicenseState::Grace)
                The system is still fully usable during the grace period, but will
                become read-only once it ends.
            @elseif ($licenseDays !== null)
                {{ $licenseDays }} {{ \Illuminate\Support\Str::plural('day', $licenseDays) }} remaining.
            @endif
        </span>

        @if ($licenseMessage = \Mazaya\License\Facades\License::message())
            <span style="opacity:.85;">{{ $licenseMessage }}</span>
        @endif
    </div>
@endif
