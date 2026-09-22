@extends('layouts.app')

@section('title', 'Nota de crédito generada')

@section('description', 'Entregue este código al cliente.')

@section('content')

@php
    $delivery = array_merge([
        'title' => 'NOTA DE CRÉDITO GENERADA',
        'subtitle' => isset($delivery['sale_return_number'])
            ? 'Devolución '.$delivery['sale_return_number'].' registrada correctamente.'
            : null,
        'close_url' => isset($delivery['sale_id']) ? route('ventas.show', $delivery['sale_id']) : null,
    ], $delivery ?? []);
@endphp

@include('notas-credito.partials.delivery', [
    'delivery' => $delivery,
    'company' => $company,
    'branch' => $branch,
])

@endsection