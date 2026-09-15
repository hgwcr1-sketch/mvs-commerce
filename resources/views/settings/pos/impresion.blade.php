@extends('layouts.app')

@section('title', 'Impresión (MVS Print)')
@section('description', 'Configura terminales POS y su impresión local mediante QZ Tray.')

@section('content')
<div class="space-y-6" x-data="mvsPrintQz({ testUrl: '{{ str_replace(route('mvs.print.terminals.test-print', 0), '/0/', '/__ID__/') }}', drawerUrl: '{{ str_replace(route('mvs.print.terminals.open-drawer', 0), '/0/', '/__ID__/') }}', signatureUrl: '{{ route('mvs.print.signature') }}', signedMode: {{ $qzSignedMode ? 'true' : 'false' }} })">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-800">Impresión (MVS Print)</h2>
            <p class="mt-1 text-sm text-slate-600">Terminales de impresión local por sucursal con QZ Tray. La impresión ocurre en este equipo; el servidor solo valida y firma.</p>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('configuracion.index') }}"
               class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
                Volver
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Conexión QZ Tray --}}
    <x-card>
        <x-slot:header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-slate-800">MVS Print (impresora local)</h3>
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold"
                      :class="connected === true ? 'bg-green-100 text-green-700' : (connected === false ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600')">
                    <span class="h-2 w-2 rounded-full" :class="connected === true ? 'bg-green-500' : (connected === false ? 'bg-red-500' : 'bg-slate-400')"></span>
                    <span x-text="connected === true ? 'Conectado' : (connected === false ? 'Desconectado' : 'Verificando…')"></span>
                </span>
            </div>
        </x-slot:header>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-sm text-slate-600">Estado del puente local entre el navegador y la impresora/cajón.</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    <button type="button" @click="check()"
                            class="min-h-11 rounded-lg bg-amber-500 px-4 py-2.5 font-semibold text-white hover:bg-amber-600">
                        Detectar MVS Print
                    </button>
                    <button type="button" @click="listPrinters()" :disabled="!connected"
                            class="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-40">
                        Ver impresoras
                    </button>
                </div>

                <template x-if="message">
                    <p class="mt-3 text-sm" x-text="message"></p>
                </template>
            </div>

            <div>
                <label for="printer_select" class="mb-1 block text-sm font-semibold text-slate-700">Impresora detectada</label>
                <select id="printer_select" x-model="printerName" :disabled="!connected" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                    <option value="">— Seleccione una impresora —</option>
                    <template x-for="printer in printers" :key="printer">
                        <option :value="printer" x-text="printer"></option>
                    </template>
                </select>
                <p class="mt-2 text-sm text-slate-500">MVS Print entrega el nombre del driver instalado en este equipo. Guárdelo en una terminal para fijar la salida.</p>
            </div>
        </div>

        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-sm font-semibold text-slate-700">Requisitos</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-600">
                <li>QZ Tray debe estar instalado y ejecutándose en este equipo.</li>
                <li>El archivo <code class="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-xs">qz-tray.js</code> se carga desde <code class="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-xs">https://localhost:8181/qz-tray.js</code> (servido por QZ Tray).</li>
                <li>En producción se requiere HTTPS para WebSocket seguro.</li>
            </ul>
        </div>
    </x-card>

    {{-- Nueva terminal --}}
    <x-card>
        <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Registrar terminal de impresión</h3></x-slot:header>

        <form method="POST" action="{{ route('mvs.print.terminals.store') }}" class="space-y-5">
            @csrf

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-slate-700">Nombre de la terminal</label>
                    <input id="name" name="name" type="text" maxlength="100" required placeholder="Caja 1 — mostrador"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3">
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="branch_id" class="mb-1 block text-sm font-semibold text-slate-700">Sucursal</label>
                    <select id="branch_id" name="branch_id" required class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">— Seleccione sucursal —</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $branchId === (int) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="paper_width" class="mb-1 block text-sm font-semibold text-slate-700">Ancho de papel</label>
                    <select id="paper_width" name="paper_width" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option value="80">80 mm</option>
                        <option value="58">58 mm</option>
                    </select>
                </div>

                <div>
                    <label for="printer_name" class="mb-1 block text-sm font-semibold text-slate-700">Impresora (opcional)</label>
                    <input id="printer_name" name="printer_name" type="text" maxlength="255" placeholder="Selecciónela desde QZ Tray"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3">
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                <label class="flex min-h-11 items-start gap-3">
                    <input type="hidden" name="auto_print" value="0">
                    <input type="checkbox" name="auto_print" value="1" class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500">
                    <span class="block">
                        <span class="font-semibold text-slate-800">Impresión automática</span>
                        <span class="text-sm text-slate-500">Imprimir automáticamente al completar operaciones (fase posterior de integración).</span>
                    </span>
                </label>
                <label class="flex min-h-11 items-start gap-3">
                    <input type="hidden" name="auto_cut" value="1">
                    <input type="checkbox" name="auto_cut" value="1" checked class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500">
                    <span class="block">
                        <span class="font-semibold text-slate-800">Corte automático</span>
                        <span class="text-sm text-slate-500">Enviar comando de corte ESC/POS al terminar el ticket.</span>
                    </span>
                </label>
                <label class="flex min-h-11 items-start gap-3">
                    <input type="hidden" name="open_drawer" value="0">
                    <input type="checkbox" name="open_drawer" value="1" class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500">
                    <span class="block">
                        <span class="font-semibold text-slate-800">Abrir cajón</span>
                        <span class="text-sm text-slate-500">Enviar comando de apertura de cajón ESC/POS al imprimir (perfil configurable en edición).</span>
                    </span>
                </label>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="min-h-11 rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">
                    Registrar terminal
                </button>
            </div>
        </form>
    </x-card>

    {{-- Terminales registradas --}}
    <x-card>
        <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Terminales registradas</h3></x-slot:header>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b bg-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left">Nombre</th>
                        <th class="px-4 py-3 text-center">Papel</th>
                        <th class="px-4 py-3 text-center">Corte</th>
                        <th class="px-4 py-3 text-center">Cajón</th>
                        <th class="px-4 py-3 text-center">Estado</th>
                        <th class="px-4 py-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($terminals as $terminal)
                        <tr class="border-b hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium">
                                {{ $terminal->name }}
                                <span class="block font-mono text-xs text-slate-400">{{ $terminal->terminal_uuid }}</span>
                                <span class="block text-xs text-slate-500">{{ $terminal->printer_name ?: 'Impresora sin asignar' }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">{{ $terminal->paper_width }} mm</td>
                            <td class="px-4 py-3 text-center">{{ $terminal->auto_cut ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center">{{ $terminal->open_drawer ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $terminal->enabled ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $terminal->enabled ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-center gap-2">
                                    <a href="{{ route('mvs.print.terminals.test-print', $terminal) }}"
                                       class="rounded-lg bg-amber-500 px-3 py-1 text-sm font-semibold text-white hover:bg-amber-600"
                                       @click.prevent="testPrint('{{ $terminal->id }}')">
                                        Imprimir prueba
                                    </a>
                                    <a href="{{ route('mvs.print.terminals.open-drawer', $terminal) }}"
                                       class="rounded-lg bg-slate-600 px-3 py-1 text-sm font-semibold text-white hover:bg-slate-700"
                                       @click.prevent="openDrawer('{{ $terminal->id }}')">
                                        Abrir cajón
                                    </a>
                                    <form action="{{ route('mvs.print.terminals.toggle', $terminal) }}" method="POST" class="inline">
                                        @csrf @method('PATCH')
                                        <button class="rounded-lg bg-slate-600 px-3 py-1 text-sm font-semibold text-white hover:bg-slate-700">
                                            {{ $terminal->enabled ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('mvs.print.terminals.destroy', $terminal) }}" method="POST"
                                          onsubmit="return confirm('¿Desea eliminar esta terminal? Esta acción no se puede deshacer.')">
                                        @csrf @method('DELETE')
                                        <button class="rounded-lg bg-red-500 px-3 py-1 text-sm font-semibold text-white hover:bg-red-600">
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-10 text-center text-slate-500">No hay terminales de impresión registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Descargar MVS Print --}}
    <x-card>
        <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Descargar MVS Print</h3></x-slot:header>
        <div class="space-y-3">
            <p class="text-sm text-slate-600">MVS Print utiliza QZ Tray como motor de impresión local. El instalador incluye QZ Tray v2.2.6 y se ejecuta de forma independiente en cada equipo POS.</p>
            <div class="flex flex-wrap gap-3">
                <button type="button" disabled
                        class="min-h-11 cursor-not-allowed rounded-lg bg-slate-300 px-5 py-2.5 font-semibold text-slate-500">
                    Descargar MVS Print para Windows
                </button>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Próximamente disponible</span>
            </div>
            <p class="text-xs text-slate-500">El instalador se distribuirá cuando la fase de pruebas físicas con impresora y cajón esté completada.</p>
        </div>
    </x-card>
</div>
@endsection