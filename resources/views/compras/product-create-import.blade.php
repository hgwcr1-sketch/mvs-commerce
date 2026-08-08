@extends('layouts.app')

@section('title','Crear producto')

@section('content')

<div class="mb-6">

<h2 class="text-xl font-semibold text-slate-800">
Crear producto desde importación
</h2>

</div>


<x-card>

<form method="POST"
action="{{ route('compras.import.product.store') }}">

@csrf


<input type="hidden" name="code" value="{{ $code }}">


<div class="grid gap-4">


<div>

<label class="text-sm">
Código
</label>

<input
value="{{ $code }}"
disabled
class="w-full rounded-lg border px-3 py-2">

</div>



<div>

<label class="text-sm">
Nombre producto
</label>

<input
name="name"
value="{{ $name }}"
class="w-full rounded-lg border px-3 py-2">

</div>



<div>

<label class="text-sm">
Costo
</label>

<input
name="cost"
value="{{ $cost }}"
class="w-full rounded-lg border px-3 py-2">

</div>


</div>

<div>

<label class="text-sm">
Categoría

<div>

<label class="text-sm">
Unidad de medida
</label>

<select
name="unit_id"
class="w-full rounded-lg border px-3 py-2">

@foreach($units as $unit)

<option value="{{ $unit->id }}">
{{ $unit->name }}
</option>

@endforeach

</select>

</div>
</label>

<select
name="category_id"
class="w-full rounded-lg border px-3 py-2">

@foreach($categories as $category)

<option value="{{ $category->id }}">
{{ $category->name }}
</option>

@endforeach

</select>

</div>

<button
class="mt-5 rounded-lg bg-amber-500 px-5 py-2 text-white">

Guardar producto

</button>


</form>


</x-card>


@endsection