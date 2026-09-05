@extends('layouts.app')

@section('content')
<div class="container-fluid mb-4">
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Traslados de Inventario</h4>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Listado</h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            Filtros
                        </button>
                        <ul class="dropdown-menu dropdown-menu-sm">
                            <li><a class="dropdown-item" href="#">Todos</a></li>
                            <li><a class="dropdown-item" href="#">Pendientes</a></li>
                            <li><a class="dropdown-item" href="#">Preparados</a></li>
                            <li><a class="dropdown-item" href="#">En tránsito</a></li>
                            <li><a class="dropdown-item" href="#">Por recibir</a></li>
                            <li><a class="dropdown-item" href="#">Recibidos</a></li>
                            <li><a class="dropdown-item" href="#">Con diferencias</a></li>
                            <li><a class="dropdown-item" href="#">Cancelados</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('transferencias.create') }}">Nuevo traslado</a></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Origen</th>
                                    <th>Destino</th>
                                    <th>Estado</th>
                                    <th>Productos</th>
                                    <th>Unidades</th>
                                    <th>Creado por</th>
                                    <th>Fecha</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transfers as $transfer)
                                <tr>
                                    <td>{{ $transfer->transfer_number }}</td>
                                    <td>{{ $transfer->fromBranch?->name }}</td>
                                    <td>{{ $transfer->toBranch?->name }}</td>
                                    <td>
                                        <span class="badge bg-{{ $transfer->status === 'completed' ? 'secondary' : ($transfer->status === 'cancelled' ? 'dark' : ($transfer->status === 'received_with_differences' ? 'warning' : 'primary')) }}">
                                            {{ ucfirst($transfer->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $transfer->items->count() }}</td>
                                    <td>{{ $transfer->items->sum('quantity') }}</td>
                                    <td>{{ $transfer->user?->name }}</td>
                                    <td>{{ $transfer->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('transferencias.show', $transfer->id) }}" class="btn btn-sm btn-link text-primary">
                                            Ver
                                        </a>
                                        @if($transfer->canBePrepared())
                                        <form action="{{ route('transferencias.prepare', $transfer->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-link text-primary p-0">Preparar</button>
                                        </form>
                                        @endif
                                        @if($transfer->canBeDispatched())
                                        <form action="{{ route('transferencias.dispatch', $transfer->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-link text-primary p-0">Despachar</button>
                                        </form>
                                        @endif
                                        @if($transfer->canBeReviewed())
                                        <form action="{{ route('transferencias.review', $transfer->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-link text-primary p-0">Revisar</button>
                                        </form>
                                        @endif
                                        @if($transfer->canBeCancelled())
                                        <form action="{{ route('transferencias.cancel', $transfer->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-link text-danger p-0">Cancelar</button>
                                        </form>
                                        @endif</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection