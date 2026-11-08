@extends('front.layout')

@section('title', '美容紀錄')

@php
    use Illuminate\Support\Facades\Storage;
@endphp

@section('content')
    <div class="page page-single">
        <div class="page-main">
            @include('front.member.tabs')

            <section class="section">
                <div class="section-head">
                    <h2><span class="sparkle" aria-hidden="true">✦</span>美容紀錄</h2>
                    <span class="muted small">共 {{ $visits->total() }} 次</span>
                </div>

                @forelse ($visits as $visit)
                    <article class="card history">
                        <div class="history-date">
                            <strong>{{ $visit->starts_at->format('n/j') }}</strong>
                            <span>{{ $visit->starts_at->format('Y') }}</span>
                        </div>
                        <div class="history-body">
                            <h3>{{ $visit->pet->name }}</h3>
                            <p class="muted small">{{ $visit->items->pluck('name')->implode('、') ?: '美容' }}</p>
                            @if ($visit->after_photos)
                                <div class="history-photos">
                                    @foreach (array_slice($visit->after_photos, 0, 4) as $photo)
                                        <a href="{{ Storage::disk('public')->url($photo) }}" target="_blank" rel="noopener">
                                            <img src="{{ Storage::disk('public')->url($photo) }}" alt="{{ $visit->pet->name }} 美容後的照片" loading="lazy">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="history-price">
                            @if ($visit->package_id)
                                <span class="status status-done">包月</span>
                            @else
                                ${{ number_format($visit->total) }}
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="empty">還沒有美容紀錄，完成第一次美容後就會出現在這裡。</div>
                @endforelse

                @if ($visits->hasPages())
                    <nav class="pager" aria-label="分頁">
                        @if ($visits->onFirstPage())
                            <span></span>
                        @else
                            <a class="btn btn-sm btn-outline" href="{{ $visits->previousPageUrl() }}">‹ 較新的</a>
                        @endif
                        <span class="muted small">第 {{ $visits->currentPage() }} / {{ $visits->lastPage() }} 頁</span>
                        @if ($visits->hasMorePages())
                            <a class="btn btn-sm btn-outline" href="{{ $visits->nextPageUrl() }}">較早的 ›</a>
                        @else
                            <span></span>
                        @endif
                    </nav>
                @endif
            </section>
        </div>
    </div>
@endsection
