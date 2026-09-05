@extends('layouts.app')

@section('content')
<div class="container-fluid mb-4">
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Detalle del Traslado</h4>
            
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            
            @if ($transfer)
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Traslado #{{ $transfer->transfer_number }}</h5>
                                    <span class="badge bg-{{ $transfer->status === 'completed' ? 'secondary' : ($transfer->status === 'cancelled' ? 'dark' : ($transfer->status === 'received_with_differences' ? 'warning' : 'primary')) }}">
                                            {{ ucfirst($transfer->status) }}
                                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Sucursal Origen</h6>
                                <p>{{ $transfer->fromBranch?->name }} ({{ $transfer->fromBranch?->code }})</p>
                            </div>
                            <div class="col-md-6">
                                <h6>Sucursal Destino</h6>
                                <p>{{ $transfer->toBranch?->name }} ({{ $transfer->toBranch?->code }})</p>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6>Observaciones</h6>
                                <p>{{ $transfer->notes ?? 'Ninguna' }}</p>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6>Responsables</h6>
                                <p><strong>Creado por:</strong> {{ $transfer->user?->name }}</p>
                                @if ($transfer->prepared_by)
                                    <p><strong>Preparado por:</strong> {{ $transfer->preparer?->name }}</p>
                                @endif
                                @if ($transfer->dispatched_by)
                                    <p><strong>Despachado por:</strong> {{ $transfer->dispatchedBy?->name }}</p>
                                @endif
                                @if ($transfer->received_by)
                                    <p><strong>Recibido por:</strong> {{ $transfer->receiver?->name }}</p>
                                @endif
                                @if ($transfer->confirmed_by)
                                    <p><strong>Confirmado por:</strong> {{ $transfer->confirmer?->name }}</p>
                                @endif
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6>Fechas</h6>
                                <p><strong>Creado:</strong> {{ $transfer->created_at->format('d/m/Y H:i') }}</p>
                                @if ($transfer->prepared_at)
                                    <p><strong>Preparado:</strong> {{ $transfer->prepared_at->format('d/m/Y H:i') }}</p>
                                @endif
                                @if ($transfer->dispatched_at)
                                    <p><strong>Despachado:</strong> {{ $transfer->dispatched_at->format('d/m/Y H:i') }}</p>
                                @endif
                                @if ($transfer->received_at)
                                    <p><strong>Recibido:</strong> {{ $transfer->received_at->format('d/m/Y H:i') }}</p>
                                @endif
                                @if ($transfer->confirmed_at)
                                    <p><strong>Confirmado:</strong> {{ $transfer->confirmed_at->format('d/m/Y H:i') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                
                <h6 class="mt-4">Productos</h6>
                <div class="table-responsive">
                    <table class="table table-borderless">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>SKU</th>
                                <th>Enviado</th>
                                <th>Recibido</th>
                                <th>Diferencia</th>
                                <th>Observación</th>
                            </thead>
                            <tbody>
                                @foreach ($transfer->items as $item)
                                <tr>
                                    <td>{{ $item->product->name }}</td>
                                    <td>{{ $internal_code = $item->product->internal_code ?? ''; $internal_code }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td>{{ $item->received_quantity ?? 0 }}</td>
                                    <td>
                                        @if ($item->difference !== null)
                                            @if ($item->isQuantityExact())
                                                <span class="text-success">Exacta</span>
                                            @elseif ($item->isQuantityShort())
                                                <span class="text-warning">Faltante</span>
                                            @elseif ($item->isQuantitySurplus())
                                                <span class="text-danger">Sobrante</span>
                                            @endif
                                        @else>
                                            <span class="text-muted">—</span>
                                        @endif</td>
                                    <td>{{ $item->item_notes ?? '' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </table>
                </div>
                
                <div class="mt-3">
                    <strong>Total enviado:</strong> {{ $transfer->items->sum('quantity') }} unidades<br>
                    <strong>Total recibido:</strong> {{ $transfer->received_quantity_total ?? 0 }} unidades<br>
                    <strong>Estado final:</strong> 
                        <span class="badge bg-{{ $transfer->status === 'completed' ? 'secondary' : ($transfer->status === 'cancelled' ? 'dark' : ($transfer->status === 'received_with_differences' ? 'warning' : 'primary')) }}">
                                        {{ ucfirst($transfer->status) }}
                                    </span>
                </div>

                <div class="mt-4 d-flex gap-2">
                    @if($transfer->canBePrepared())
                    <form action="{{ route('transferencias.prepare', $transfer->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Preparar</button>
                    </form>
                    @endif
                    @if($transfer->canBeDispatched())
                    <form action="{{ route('transferencias.dispatch', $transfer->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning">Despachar</button>
                    </form>
                    @endif
                    @if($transfer->canBeReviewed())
                    <form action="{{ route('transferencias.review', $transfer->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-info">Iniciar Revisión</button>
                    </form>
                    @endif
                    @if($transfer->canBeCancelled())
                    <form action="{{ route('transferencias.cancel', $transfer->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-danger">Cancelar</button>
                    </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
@endsection