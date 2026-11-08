@php($count = \App\Filament\Pages\VisitReminders::pendingCount())
<a href="{{ \App\Filament\Pages\VisitReminders::getUrl() }}" class="bibi-bell" aria-label="訊息通知：{{ $count }} 筆待處理">
    {{ svg('heroicon-o-bell') }}
    @if ($count)
        <span class="bibi-bell-count">{{ $count }}</span>
    @endif
</a>
