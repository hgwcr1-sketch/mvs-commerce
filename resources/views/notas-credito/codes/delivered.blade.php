@extends('layouts.app')

@section('title', 'Código regenerado')

@section('description', 'Entregue este nuevo código al cliente.')

@section('content')

@include('notas-credito.partials.delivery', [
    'delivery' => array_merge([
        'title' => 'Código regenerado',
        'warning' => 'Entregue este nuevo código al cliente. El código anterior ya no es válido. Este código se muestra una única vez y no podrá consultarse nuevamente.',
        'close_url' => route('notas-credito.codes.index'),
        'close_label' => 'Volver a la lista',
    ], $delivery),
    'company' => $company,
    'branch' => $branch,
])

@endsection