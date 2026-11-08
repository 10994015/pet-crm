@php($user = auth()->user())
@if ($user)
    <div class="bibi-me">
        <span class="bibi-me-avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
        <span class="bibi-me-text">
            <span class="bibi-me-name">{{ $user->name }}</span>
            <span class="bibi-me-role">{{ $user->role->getLabel() }}</span>
        </span>
    </div>
@endif
