<div style="display: flex; flex-direction: column; gap: .5rem;">
    @forelse ($movements as $movement)
        <div style="display: grid; grid-template-columns: 1fr auto; gap: .125rem .75rem; padding-bottom: .5rem; border-bottom: 1px solid var(--gray-200);">
            <strong>
                {{ $movement->reason->getLabel() }}
                <span style="color: {{ $movement->change < 0 ? 'var(--danger-600)' : 'var(--success-600)' }};">{{ $movement->change > 0 ? '+' : '' }}{{ $movement->change }}</span>
            </strong>
            <span>結餘 {{ $movement->balance }}</span>
            <small style="color: var(--gray-500); grid-column: 1 / -1;">
                {{ $movement->created_at->format('Y/m/d H:i') }}
                @if ($movement->user) · {{ $movement->user->name }} @endif
                @if ($movement->order) · {{ $movement->order->order_number }} @endif
                @if ($movement->note) · {{ $movement->note }} @endif
            </small>
        </div>
    @empty
        <p style="color: var(--gray-500);">還沒有庫存紀錄。</p>
    @endforelse
</div>
