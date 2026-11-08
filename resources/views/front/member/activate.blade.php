@extends('front.layout')

@section('title', '開通會員')

@section('content')
    <div class="auth-wrap">
        <section class="card auth">
            <x-front.art name="cat" class="auth-art" />
            <h1 class="hand">開通會員／重設密碼</h1>
            <p class="muted">輸入在店裡留的手機號碼，和店家給您的 6 碼開通碼（{{ \App\Models\Customer::ACTIVATION_DAYS }} 天內有效），再設定一組密碼。</p>

            <form action="{{ route('front.member.activate.attempt') }}" method="post" class="stack auth-form">
                @csrf
                <label class="field">
                    <span>手機號碼</span>
                    <input class="input" type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912-345-678" autocomplete="username" inputmode="tel" required>
                    @error('phone') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>開通碼</span>
                    <input class="input code-input" type="text" name="code" value="{{ old('code') }}" placeholder="例如 7KQ2MX" autocapitalize="characters" autocomplete="one-time-code" maxlength="12" required>
                    @error('code') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>設定密碼</span>
                    <input class="input" type="password" name="password" autocomplete="new-password" minlength="6" required>
                    <small class="muted">至少 6 個字，要有英文字母和數字</small>
                    @error('password') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>再輸入一次密碼</span>
                    <input class="input" type="password" name="password_confirmation" autocomplete="new-password" required>
                </label>
                <button class="btn btn-block" type="submit">完成開通 <span aria-hidden="true">›</span></button>
            </form>

            <p class="muted small">已經有密碼了？<a class="link" href="{{ route('front.member.login') }}">回到登入</a></p>
        </section>
    </div>
@endsection
