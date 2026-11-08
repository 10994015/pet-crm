@extends('front.layout')

@section('title', '我要預約')
@section('description', '線上預約寵物美容：選擇服務、挑選時段、填寫資料，三步驟完成，店家確認後就約好了。')

@section('content')
    <livewire:booking-wizard />
@endsection
