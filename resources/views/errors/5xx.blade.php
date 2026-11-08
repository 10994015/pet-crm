@extends('errors.layout')

@section('code', $exception->getStatusCode())
@section('title', '系統暫時無法使用')
@section('message', '請稍後再試，急著預約的話請直接來電或到店。')
