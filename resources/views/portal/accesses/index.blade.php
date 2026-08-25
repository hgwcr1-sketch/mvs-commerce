<div>
    <h1>Accesos al Portal del Cliente</h1>
    
    <form action="{{ route('portal.accesses.store') }}" method="POST">
        @csrf
        <select name="customer_id" required>
            <option value="">Seleccionar cliente</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
            @endforeach
        </select>
        <button type="submit">Generar acceso</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Usuario que generó</th>
                <th>Último uso</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accesses as $access)
                <tr>
                    <td>{{ $access->customer->name }}</td>
                    <td>{{ $access->user->name ?? 'N/A' }}</td>
                    <td>{{ $access->last_used_at?->format('Y-m-d H:i') ?? 'Nunca' }}</td>
                    <td>
                        <form action="{{ route('portal.accesses.revoke', $access->customer) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <button type="submit" onclick="return confirm('¿Revocar acceso?')">Revocar</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $accesses->links() }}
</div>