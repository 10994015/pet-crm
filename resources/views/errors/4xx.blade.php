@extends('errors.layout')

@section('code', $exception->getStatusCode())
@section('title', '這個動作無法完成')
@section('message', '請回到首頁重新操作一次。')
