@extends('front.layout')

@section('title', '會員登入')

@section('content')
    <div class="auth-wrap">
        <section class="card auth">
            <x-front.art name="dog" class="auth-art" />
            <h1 class="hand">會員登入</h1>
            <p class="muted">登入後可以看毛孩的美容紀錄、包月剩餘次數，預約也不用再填資料。</p>

            <form action="{{ route('front.member.login.attempt') }}" method="post" class="stack auth-form">
                @csrf
                <label class="field">
                    <span>手機號碼</span>
                    <input class="input" type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912-345-678" autocomplete="username" inputmode="tel" required autofocus>
                    @error('phone') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>密碼</span>
                    <input class="input" type="password" name="password" autocomplete="current-password" required>
                    @error('password') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>記住我（自己的手機再勾）</span>
                </label>
                <button class="btn btn-block" type="submit">登入 <span aria-hidden="true">›</span></button>
            </form>

            <div class="auth-alt">
                <strong>第一次使用？忘記密碼？</strong>
                <p class="muted small">請跟店家索取「會員開通碼」，到店或來電都可以，再用它設定密碼。</p>
                <a class="btn btn-outline btn-block" href="{{ route('front.member.activate') }}">用開通碼設定密碼</a>
            </div>
        </section>
    </div>
@endsection
