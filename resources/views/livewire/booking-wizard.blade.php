@php
    use App\Livewire\BookingWizard;
    use Illuminate\Support\Carbon;

    $stepNames = [1 => '選擇服務', 2 => '選擇時段', 3 => '填寫資料'];
    $main = $this->chosen->first();
    $when = $date && $time ? Carbon::parse("{$date} {$time}")->locale('zh_TW')->isoFormat('M/D（dd）HH:mm') : null;
@endphp

<div class="page">
    <div class="page-main">
        @if (! $enabled)
            <section class="card notice">
                <x-front.art name="sitting" class="notice-art" />
                <h1 class="hand">目前暫停線上預約</h1>
                <p>請直接來電或到店預約，謝謝您！</p>
                <a class="btn" href="#contact">聯絡我們</a>
            </section>
        @elseif ($step === 4)
            <section class="card done">
                <x-front.art name="dog" class="done-art" />
                <h1 class="hand">預約送出囉！</h1>
                <p>我們收到 <strong>{{ $petName }}</strong> 在 <strong>{{ $bookedAt }}</strong> 的預約，店家確認後就完成了。</p>
                <div class="code-box">
                    <span>預約代碼</span>
                    <strong>{{ $bookingCode }}</strong>
                    <small>請截圖保存，之後用手機號碼加這組代碼就能查詢預約</small>
                </div>
                <div class="done-actions">
                    @if ($this->member())
                        <a class="btn btn-outline" href="{{ route('front.member.home') }}">到會員中心</a>
                    @else
                        <a class="btn btn-outline" href="{{ route('front.my-booking') }}">查詢我的預約</a>
                    @endif
                    <a class="btn" href="{{ route('front.home') }}">回到首頁</a>
                </div>
            </section>
        @else
            <section class="card wizard">
                <div class="wizard-head">
                    <h1 class="hand">線上預約</h1>
                    <ol class="steps">
                        @foreach ($stepNames as $number => $label)
                            <li @class(['is-current' => $step === $number, 'is-done' => $step > $number])>
                                <button type="button" wire:click="goTo({{ $number }})" @disabled($number >= $step)>
                                    <span>{{ $step > $number ? '✓' : $number }}</span>{{ $label }}
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </div>

                @error('form')
                    <p class="alert" role="alert">{{ $message }}</p>
                @enderror

                @if ($step === 1)
                    @if ($this->myPets->isNotEmpty())
                        <div class="block">
                            <h2 class="block-title">幫誰預約？</h2>
                            <div class="pet-pick-row" data-pick-group>
                                @foreach ($this->myPets as $pet)
                                    <button type="button" data-pick wire:click="choosePet({{ $pet->id }})" @class(['pet-pick', 'is-on' => $petId === $pet->id])
                                        aria-pressed="{{ $petId === $pet->id ? 'true' : 'false' }}">
                                        <span class="detail-art tone-{{ $loop->index % 4 }}"><x-front.art :name="$pet->species?->value === 'cat' ? 'cat' : 'dog'" /></span>
                                        <span><strong>{{ $pet->name }}</strong><small>{{ collect([$pet->breed, $pet->size?->getLabel()])->filter()->implode(' · ') }}</small></span>
                                    </button>
                                @endforeach
                                <button type="button" data-pick wire:click="choosePet(null)" @class(['pet-pick', 'pet-pick-new', 'is-on' => $petId === null])>＋ 新的毛孩</button>
                            </div>
                        </div>
                    @endif

                    <div class="block" @if ($this->pet) hidden @endif>
                        <h2 class="block-title">毛孩的體型</h2>
                        <div class="size-row" role="radiogroup" aria-label="毛孩的體型" data-pick-group>
                            @foreach ($sizes as $option)
                                <label data-pick @class(['size-option', 'is-on' => $size === $option->value])>
                                    <input type="radio" wire:model.live="size" value="{{ $option->value }}" class="sr-only">
                                    <strong>{{ $option->getLabel() }}</strong>
                                    <span>{{ BookingWizard::sizeHint($option) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="block">
                        <h2 class="block-title">美容服務 <small>選一項</small></h2>
                        @error('serviceId') <p class="field-error">{{ $message }}</p> @enderror
                        <div class="pick-grid" data-pick-group>
                            @forelse ($this->services as $service)
                                <button type="button" data-pick wire:click="chooseService({{ $service->id }})"
                                    @class(['pick', 'is-on' => $serviceId === $service->id]) aria-pressed="{{ $serviceId === $service->id ? 'true' : 'false' }}">
                                    <span @class(['pick-media', 'tone-'.($loop->index % 4)])>
                                        @if ($service->photo)
                                            <img src="{{ Storage::disk('public')->url($service->photo) }}" alt="">
                                        @else
                                            <x-front.art :name="$service->artName()" />
                                        @endif
                                    </span>
                                    <span class="pick-body">
                                        <strong>{{ $service->name }}</strong>
                                        <span>{{ $service->description ?: '約 '.($service->duration_minutes ?: 60).' 分鐘' }}</span>
                                    </span>
                                    <span class="pick-price">${{ number_format($this->priceOf($service)) }}</span>
                                    <span class="pick-check" aria-hidden="true">✓</span>
                                </button>
                            @empty
                                <p class="empty">目前沒有開放線上預約的服務，請直接來電。</p>
                            @endforelse
                        </div>
                    </div>

                    @if ($this->addons->isNotEmpty())
                        <div class="block">
                            <h2 class="block-title">加購項目 <small>可複選，不需要可跳過</small></h2>
                            <div class="chip-row">
                                @foreach ($this->addons as $addon)
                                    <button type="button" data-pick="toggle" wire:click="toggleAddon({{ $addon->id }})"
                                        @class(['chip', 'is-on' => in_array($addon->id, $addonIds, true)])
                                        aria-pressed="{{ in_array($addon->id, $addonIds, true) ? 'true' : 'false' }}">
                                        {{ $addon->name }} <span>+${{ number_format($this->priceOf($addon)) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

                @if ($step === 2)
                    <div class="block">
                        <h2 class="block-title">選擇日期</h2>
                        @error('date') <p class="field-error">{{ $message }}</p> @enderror
                        <div class="day-row" data-pick-group>
                            @foreach ($this->days as $day)
                                <button type="button" data-pick wire:click="chooseDate('{{ $day->toDateString() }}')"
                                    @class(['day', 'is-on' => $date === $day->toDateString()])>
                                    <span>{{ $day->isToday() ? '今天' : ($day->isTomorrow() ? '明天' : '週'.$day->locale('zh_TW')->isoFormat('dd')) }}</span>
                                    <strong>{{ $day->day }}</strong>
                                    <span>{{ $day->month }} 月</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="block">
                        <h2 class="block-title">選擇時段 <small>這次大約需要 {{ $this->minutes }} 分鐘</small></h2>
                        @error('time') <p class="field-error">{{ $message }}</p> @enderror
                        @if (! $date)
                            <p class="empty">先選一個日期，就會看到可以預約的時段。</p>
                        @elseif (! collect($this->times)->contains('available', true))
                            <p class="empty">這天已經約滿了，換一天看看吧！</p>
                        @else
                            <div class="slot-grid" wire:loading.class="is-loading" wire:target="chooseDate" data-pick-group>
                                @foreach ($this->times as $slot)
                                    <button type="button" data-pick wire:click="chooseTime('{{ $slot['time'] }}')" @disabled(! $slot['available'])
                                        @class(['slot', 'is-on' => $time === $slot['time']])>
                                        {{ $slot['time'] }}
                                    </button>
                                @endforeach
                            </div>
                            <p class="muted small">灰色的時段已額滿。</p>
                        @endif
                    </div>
                @endif

                @if ($step === 3)
                    <form wire:submit="submit" class="form-grid" id="booking-form">
                        @if ($member = $this->member())
                            <p class="member-note field-wide">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/></svg>
                                <span>以會員 <strong>{{ $member->name }}</strong>（{{ $member->phone }}）預約{{ $this->pet ? "，幫 {$this->pet->name} 約美容" : '' }}</span>
                            </p>
                        @else
                            <label class="field">
                                <span>手機號碼 <em>*</em></span>
                                <input class="input" type="tel" wire:model="phone" autocomplete="tel" placeholder="0912-345-678" inputmode="tel">
                                @error('phone') <small class="field-error">{{ $message }}</small> @enderror
                            </label>
                            <label class="field">
                                <span>飼主姓名 <em>*</em></span>
                                <input class="input" type="text" wire:model="name" autocomplete="name" placeholder="王小美">
                                @error('name') <small class="field-error">{{ $message }}</small> @enderror
                            </label>
                        @endif
                        @unless ($this->pet)
                            <label class="field">
                                <span>毛孩名字 <em>*</em></span>
                                <input class="input" type="text" wire:model="petName" placeholder="豆豆">
                                @error('petName') <small class="field-error">{{ $message }}</small> @enderror
                            </label>
                            <div class="field">
                                <span>種類</span>
                                <div class="seg" data-pick-group>
                                    <label data-pick @class(['is-on' => $species === 'dog'])><input type="radio" wire:model.live="species" value="dog" class="sr-only">🐶 狗狗</label>
                                    <label data-pick @class(['is-on' => $species === 'cat'])><input type="radio" wire:model.live="species" value="cat" class="sr-only">🐱 貓咪</label>
                                </div>
                            </div>
                            <label class="field">
                                <span>品種</span>
                                <input class="input" type="text" wire:model="breed" placeholder="例如：貴賓、柴犬">
                                @error('breed') <small class="field-error">{{ $message }}</small> @enderror
                            </label>
                        @endunless
                        <label class="field field-wide">
                            <span>想跟美容師說的話</span>
                            <textarea class="input" wire:model="notes" rows="3" placeholder="例如：會怕吹風機、想剪短一點"></textarea>
                            @error('notes') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        @if ($this->needsPrivacyConsent())
                            <div class="field-wide">
                                <label class="consent">
                                    <input type="checkbox" wire:model="privacyAccepted">
                                    <span>我已閱讀並同意<a href="{{ route('front.privacy') }}" target="_blank" rel="noopener">隱私權聲明</a>，了解店家會使用以上資料處理這次預約。</span>
                                </label>
                                @error('privacyAccepted') <small class="field-error">{{ $message }}</small> @enderror
                            </div>
                        @endif
                        <label class="hp" aria-hidden="true">網站 <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label>
                    </form>
                @endif

                <div class="wizard-foot">
                    @if ($step > 1)
                        <button type="button" class="btn btn-ghost" wire:click="back">‹ 上一步</button>
                    @else
                        <span></span>
                    @endif

                    @if ($step < 3)
                        <button type="button" class="btn" wire:click="next" wire:loading.attr="disabled">下一步 <span aria-hidden="true">›</span></button>
                    @else
                        <button type="submit" form="booking-form" class="btn" wire:loading.attr="disabled" wire:target="submit">
                            <span wire:loading.remove wire:target="submit">確認預約</span>
                            <span wire:loading wire:target="submit">送出中…</span>
                        </button>
                    @endif
                </div>
            </section>
        @endif
    </div>

    <aside class="page-aside">
        @if ($enabled && $step < 4)
            <section class="card summary">
                <div class="card-head">
                    <h2>預約明細</h2>
                    <x-front.art name="paw" class="card-paw" />
                </div>
                <dl>
                    @if ($this->pet)
                        <div><dt>毛孩</dt><dd>{{ $this->pet->name }}</dd></div>
                    @endif
                    <div><dt>服務</dt><dd>{{ $main?->name ?? '還沒選' }}</dd></div>
                    @if ($this->chosen->count() > 1)
                        <div><dt>加購</dt><dd>{{ $this->chosen->skip(1)->pluck('name')->implode('、') }}</dd></div>
                    @endif
                    <div><dt>體型</dt><dd>{{ \App\Enums\PetSize::from($size)->getLabel() }}</dd></div>
                    <div><dt>時間</dt><dd>{{ $when ?? '還沒選' }}</dd></div>
                    @if ($main)
                        <div><dt>預計時長</dt><dd>約 {{ $this->minutes }} 分鐘</dd></div>
                    @endif
                </dl>
                <div class="summary-total">
                    <span>預估金額</span>
                    <strong>${{ number_format($this->estimate) }}</strong>
                </div>
                <p class="muted small">實際金額依毛孩的毛況與體型，以現場為準；到店付款即可。</p>
            </section>
        @endif

        <section class="card help">
            <x-front.art name="sitting" class="help-art" />
            <div>
                <strong>預約小提醒</strong>
                <ul>
                    <li>請準時到店，遲到可能需要改時段</li>
                    <li>有皮膚狀況或會咬人，請先在備註告訴我們</li>
                    <li>要改時間或取消，請直接來電</li>
                </ul>
                @if ($notice = \App\Models\Setting::text('booking_notice'))
                    <p class="help-notice">{{ $notice }}</p>
                @endif
            </div>
        </section>
    </aside>
</div>
