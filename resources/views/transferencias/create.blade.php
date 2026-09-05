@extends('layouts.app')

@section('content')
<div class="container-fluid mb-4">
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Nuevo Traslado</h4>
            
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form action="{{ route('transferencias.store') }}" method="POST" class="needs-validation" novalidate>
                @csrf
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="from_branch" class="form-label">Sucursal Origen</label>
                        <select name="from_branch" id="from_branch" class="form-select" required>
                            <option value="" disabled>Seleccionar...</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ session('from_branch_id') == $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }} ({{ $branch->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback">Por favor seleccione una sucursal origen.</div>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="to_branch" class="form-label">Sucursal Destino</label>
                        <select name="to_branch" id="to_branch" class="form-select" required>
                            <option value="" disabled>Seleccionar...</option>
                            @foreach ($branches as $branch)
                                @if ($branch->id != session('from_branch'))
                                    <option value="{{ $branch->id }}" {{ old('to_branch') == $branch->id ? 'selected' : '' }}">
                                        {{ $branch->name }} ({{ $branch->code }})
                                    </option>
                                @endif
                            @endforeach
                        </select>
                        <div class="invalid-feedback">Por favor seleccione una sucursal destino.</div>
                    </div>
                </div>
                
                <input type="hidden" name="notes" value="{{ old('notes') }}">

                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">Productos</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-borderless mb-0">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Código</th>
                                        <th>Stock Origen</th>
                                        <th>Cantidad</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="products-table">
                                    <tr id="empty-state">
                                        <td colspan="5" class="text-center text-muted">
                                            <p>Buscar productos para agregarlos</p>
                                        </p>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <hr class="my-3">
                        
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="input-group" style="max-width: 300px;">
                                <input type="text" class="form-control" id="product-search" placeholder="Buscar producto...">
                                <button class="btn btn-outline-secondary" type="button" id="add-product-btn">
                                    <i class="bi bi-plus me-1"></i> Agregar
                                </button>
                            </div>
                            <div>
                                <span class="text-muted small" id="total-products">0 productos</span>
                                <span class="text-muted small" id="total-units">0 unidades</span>
                            </div>
                        </div>
                        
                        <div class="alert alert-info small mt-2" id="duplicate-warning" style="display: none;">
                            Producto duplicado. Se consolidó la cantidad.
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <label for="observations" class="form-label">Observaciones</label>
                    <textarea name="observations" class="form-control" rows="3" placeholder="Observaciones del traslado...">{{ old('observations') }}</textarea>
                </div>
                
                <hr class="my-3">
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send me-1"></i> Crear Traslado
                </button>
                <a href="{{ route('transferencias.index') }}" class="btn btn-link">Cancelar</a>
            </form>
        </div>
    </div>
</div>
@endsection