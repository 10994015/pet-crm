@extends('front.layout')

@section('title', '我的資料')

@section('content')
    <div class="page page-single">
        <div class="page-main">
            @include('front.member.tabs')

            <div class="profile-grid">
                <section class="card">
                    <div class="card-head"><h2>聯絡資料</h2></div>
                    <form action="{{ route('front.member.profile.update') }}" method="post" class="stack">
                        @csrf
                        @method('put')
                        <div class="field">
                            <span>姓名</span>
                            <p class="readonly">{{ $member->name }}</p>
                        </div>
                        <div class="field">
                            <span>手機號碼（登入帳號）</span>
                            <p class="readonly">{{ $member->phone }}</p>
                            <small class="muted">要改姓名或換手機號碼，請聯絡店家幫您更新。</small>
                        </div>
                        <label class="field">
                            <span>備用電話</span>
                            <input class="input" type="tel" name="backup_phone" value="{{ old('backup_phone', $member->backup_phone) }}">
                            @error('backup_phone') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>LINE ID</span>
                            <input class="input" type="text" name="line_id" value="{{ old('line_id', $member->line_id) }}">
                            @error('line_id') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>電子郵件</span>
                            <input class="input" type="email" name="email" value="{{ old('email', $member->email) }}" autocomplete="email">
                            @error('email') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>地址</span>
                            <input class="input" type="text" name="address" value="{{ old('address', $member->address) }}" autocomplete="street-address">
                            @error('address') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <button class="btn" type="submit">儲存資料</button>
                    </form>
                </section>

                <section class="card">
                    <div class="card-head"><h2>變更密碼</h2></div>
                    <form action="{{ route('front.member.password.update') }}" method="post" class="stack">
                        @csrf
                        @method('put')
                        <label class="field">
                            <span>目前的密碼</span>
                            <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                            @error('current_password') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>新密碼</span>
                            <input class="input" type="password" name="password" autocomplete="new-password" minlength="6" required>
                            <small class="muted">至少 6 個字，要有英文字母和數字</small>
                            @error('password') <small class="field-error">{{ $message }}</small> @enderror
                        </label>
                        <label class="field">
                            <span>再輸入一次新密碼</span>
                            <input class="input" type="password" name="password_confirmation" autocomplete="new-password" required>
                        </label>
                        <button class="btn btn-outline" type="submit">更新密碼</button>
                    </form>
                    <p class="muted small profile-note">忘記密碼的話，請跟店家索取新的開通碼重新設定。</p>
                </section>
            </div>
        </div>
    </div>
@endsection
