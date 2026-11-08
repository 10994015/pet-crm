{{-- Hand-drawn style illustrations for the website, so it looks finished before the shop has photos. --}}
@props(['name' => 'dog'])

@switch($name)
    @case('dog')
        <svg viewBox="0 0 240 220" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <ellipse cx="120" cy="206" rx="78" ry="9" fill="#e9d6c3" opacity=".7" />
            <path d="M52 92c-22 6-30 40-18 66 6 13 22 14 28 2 8-16 6-44 4-60-1-7-7-10-14-8z" fill="#d79c6f" />
            <path d="M188 92c22 6 30 40 18 66-6 13-22 14-28 2-8-16-6-44-4-60 1-7 7-10 14-8z" fill="#d79c6f" />
            <circle cx="96" cy="56" r="22" fill="#f1c9a2" />
            <circle cx="120" cy="46" r="25" fill="#f4cfa9" />
            <circle cx="145" cy="56" r="22" fill="#f1c9a2" />
            <ellipse cx="120" cy="120" rx="70" ry="66" fill="#f4cfa9" />
            <ellipse cx="120" cy="150" rx="40" ry="30" fill="#fbe6d1" />
            <circle cx="92" cy="114" r="9" fill="#3b2a22" />
            <circle cx="148" cy="114" r="9" fill="#3b2a22" />
            <circle cx="95" cy="110" r="3" fill="#fff" />
            <circle cx="151" cy="110" r="3" fill="#fff" />
            <ellipse cx="78" cy="138" rx="10" ry="6" fill="#f3a9a0" opacity=".55" />
            <ellipse cx="162" cy="138" rx="10" ry="6" fill="#f3a9a0" opacity=".55" />
            <ellipse cx="120" cy="136" rx="11" ry="8" fill="#3b2a22" />
            <path d="M120 144v8m-14 0c6 8 22 8 28 0" stroke="#3b2a22" stroke-width="3" fill="none" stroke-linecap="round" />
            <path d="M112 156c0 12 16 12 16 0z" fill="#f08a8a" />
            <ellipse cx="88" cy="198" rx="20" ry="13" fill="#f4cfa9" />
            <ellipse cx="152" cy="198" rx="20" ry="13" fill="#f4cfa9" />
            <path d="M82 196v6m8-7v7m60-6v6m8-7v7" stroke="#d79c6f" stroke-width="2.5" stroke-linecap="round" />
        </svg>
        @break

    @case('cat')
        <svg viewBox="0 0 240 220" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <ellipse cx="120" cy="206" rx="74" ry="9" fill="#e5ddd3" opacity=".8" />
            <path d="M58 104 66 36l46 40z" fill="#b9a99a" />
            <path d="M182 104l-8-68-46 40z" fill="#b9a99a" />
            <path d="M70 92 74 52l26 24z" fill="#f2c4bd" />
            <path d="M170 92l-4-40-26 24z" fill="#f2c4bd" />
            <ellipse cx="120" cy="128" rx="72" ry="64" fill="#d9cdc1" />
            <path d="M104 72c4 12 4 22 0 30m16-34v32m16-28c-4 12-4 22 0 30" stroke="#a8978a" stroke-width="5" stroke-linecap="round" fill="none" />
            <ellipse cx="120" cy="154" rx="34" ry="24" fill="#f6efe8" />
            <ellipse cx="92" cy="122" rx="9" ry="11" fill="#3b2a22" />
            <ellipse cx="148" cy="122" rx="9" ry="11" fill="#3b2a22" />
            <circle cx="95" cy="117" r="3" fill="#fff" />
            <circle cx="151" cy="117" r="3" fill="#fff" />
            <path d="M113 142h14l-7 8z" fill="#e58b8b" />
            <path d="M120 150c-4 8-12 8-16 4m16-4c4 8 12 8 16 4" stroke="#3b2a22" stroke-width="2.5" fill="none" stroke-linecap="round" />
            <path d="M60 146h-26m26 8-24 6m124-14h26m-26 8 24 6" stroke="#8b7b6f" stroke-width="2" stroke-linecap="round" />
            <ellipse cx="78" cy="142" rx="9" ry="5" fill="#f3a9a0" opacity=".5" />
            <ellipse cx="162" cy="142" rx="9" ry="5" fill="#f3a9a0" opacity=".5" />
        </svg>
        @break

    @case('bath')
        <svg viewBox="0 0 160 120" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <circle cx="46" cy="22" r="9" fill="#fff" stroke="#bfe0ee" stroke-width="2" />
            <circle cx="64" cy="12" r="6" fill="#fff" stroke="#bfe0ee" stroke-width="2" />
            <circle cx="112" cy="18" r="7" fill="#fff" stroke="#bfe0ee" stroke-width="2" />
            <path d="M62 52c-10 2-14 16-8 26 3 5 10 5 12 0 3-7 2-18 1-24-1-2-3-3-5-2zm36 0c10 2 14 16 8 26-3 5-10 5-12 0-3-7-2-18-1-24 1-2 3-3 5-2z" fill="#d79c6f" />
            <ellipse cx="80" cy="66" rx="26" ry="24" fill="#f4cfa9" />
            <circle cx="70" cy="44" r="10" fill="#fff" /><circle cx="82" cy="40" r="12" fill="#fff" /><circle cx="94" cy="45" r="9" fill="#fff" />
            <circle cx="71" cy="64" r="3.5" fill="#3b2a22" /><circle cx="89" cy="64" r="3.5" fill="#3b2a22" />
            <ellipse cx="80" cy="73" rx="4.5" ry="3.5" fill="#3b2a22" />
            <path d="M76 80c2 3 6 3 8 0" stroke="#3b2a22" stroke-width="2" fill="none" stroke-linecap="round" />
            <path d="M22 80h116c0 18-14 30-30 30H52c-16 0-30-12-30-30z" fill="#9fd3e6" />
            <rect x="16" y="74" width="128" height="10" rx="5" fill="#c9e7f2" />
            <circle cx="40" cy="78" r="8" fill="#fff" /><circle cx="54" cy="74" r="9" fill="#fff" /><circle cx="108" cy="76" r="9" fill="#fff" /><circle cx="122" cy="79" r="7" fill="#fff" />
        </svg>
        @break

    @case('scissors')
        <svg viewBox="0 0 160 120" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <path d="M44 74c-12 4-16 22-8 34 4 6 13 6 15 0 4-10 3-24 1-31-1-3-5-4-8-3zm72 0c12 4 16 22 8 34-4 6-13 6-15 0-4-10-3-24-1-31 1-3 5-4 8-3z" fill="#e8d9cc" />
            <ellipse cx="80" cy="84" rx="38" ry="32" fill="#fffaf5" />
            <circle cx="62" cy="58" r="14" fill="#fffaf5" /><circle cx="80" cy="52" r="16" fill="#fffaf5" /><circle cx="98" cy="58" r="14" fill="#fffaf5" />
            <circle cx="66" cy="82" r="4.5" fill="#3b2a22" /><circle cx="94" cy="82" r="4.5" fill="#3b2a22" />
            <ellipse cx="80" cy="94" rx="6" ry="4.5" fill="#3b2a22" />
            <path d="M74 102c3 4 9 4 12 0" stroke="#3b2a22" stroke-width="2" fill="none" stroke-linecap="round" />
            <g transform="translate(112 14) rotate(30)">
                <path d="M6 0 22 36M22 0 6 36" stroke="#8a7465" stroke-width="4" stroke-linecap="round" />
                <circle cx="6" cy="44" r="7" fill="none" stroke="#efae7e" stroke-width="4" />
                <circle cx="22" cy="44" r="7" fill="none" stroke="#efae7e" stroke-width="4" />
            </g>
        </svg>
        @break

    @case('spa')
        <svg viewBox="0 0 160 120" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <path d="M50 66c-12 3-16 22-9 34 4 6 12 6 14 0 4-9 3-24 1-31-1-3-3-4-6-3zm60 0c12 3 16 22 9 34-4 6-12 6-14 0-4-9-3-24-1-31 1-3 3-4 6-3z" fill="#d79c6f" />
            <ellipse cx="80" cy="82" rx="34" ry="31" fill="#f4cfa9" />
            <path d="M58 52c8-14 36-14 44 0" fill="#f6b7c3" />
            <circle cx="66" cy="48" r="8" fill="#f6b7c3" /><circle cx="94" cy="48" r="8" fill="#f6b7c3" /><circle cx="80" cy="46" r="5" fill="#ef8fa3" />
            <path d="M62 80c3-3 7-3 10 0m16 0c3-3 7-3 10 0" stroke="#3b2a22" stroke-width="2.5" fill="none" stroke-linecap="round" />
            <ellipse cx="80" cy="92" rx="5" ry="4" fill="#3b2a22" />
            <path d="M75 99c3 3 7 3 10 0" stroke="#3b2a22" stroke-width="2" fill="none" stroke-linecap="round" />
            <ellipse cx="60" cy="94" rx="6" ry="4" fill="#f3a9a0" opacity=".55" /><ellipse cx="100" cy="94" rx="6" ry="4" fill="#f3a9a0" opacity=".55" />
            <path d="M130 30l3 8 8 3-8 3-3 8-3-8-8-3 8-3zM28 42l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" fill="#efc46b" />
        </svg>
        @break

    @case('paw')
        <svg viewBox="0 0 64 64" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <ellipse cx="32" cy="42" rx="14" ry="12" fill="currentColor" />
            <ellipse cx="14" cy="28" rx="6" ry="8" fill="currentColor" />
            <ellipse cx="25" cy="16" rx="6" ry="8" fill="currentColor" />
            <ellipse cx="39" cy="16" rx="6" ry="8" fill="currentColor" />
            <ellipse cx="50" cy="28" rx="6" ry="8" fill="currentColor" />
        </svg>
        @break

    @case('sitting')
        <svg viewBox="0 0 180 170" {{ $attributes->merge(['class' => 'art']) }} aria-hidden="true">
            <ellipse cx="90" cy="160" rx="70" ry="8" fill="#ead7c3" />
            <path d="M58 150c-6-32 2-62 32-62s38 30 32 62z" fill="#fffaf5" stroke="#6b5243" stroke-width="3" stroke-linejoin="round" />
            <path d="M120 140c18-2 26-14 22-26" stroke="#6b5243" stroke-width="3" fill="none" stroke-linecap="round" />
            <ellipse cx="90" cy="62" rx="34" ry="30" fill="#fffaf5" stroke="#6b5243" stroke-width="3" />
            <path d="M60 50c-12 2-16 22-8 32 4 5 10 3 11-2" fill="#f4cfa9" stroke="#6b5243" stroke-width="3" stroke-linejoin="round" />
            <path d="M120 50c12 2 16 22 8 32-4 5-10 3-11-2" fill="#f4cfa9" stroke="#6b5243" stroke-width="3" stroke-linejoin="round" />
            <circle cx="78" cy="60" r="3.5" fill="#3b2a22" /><circle cx="102" cy="60" r="3.5" fill="#3b2a22" />
            <ellipse cx="90" cy="70" rx="5" ry="4" fill="#3b2a22" />
            <path d="M84 77c3 4 9 4 12 0" stroke="#3b2a22" stroke-width="2" fill="none" stroke-linecap="round" />
            <path d="M74 150v-14m32 14v-14" stroke="#6b5243" stroke-width="3" stroke-linecap="round" />
        </svg>
        @break
@endswitch
