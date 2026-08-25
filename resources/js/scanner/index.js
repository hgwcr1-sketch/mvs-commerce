/**
 * R02-B — Capa de escaneo por cámara.
 *
 * Componente Alpine reutilizable (no acoplado al POS) + motor de detección.
 * Contrato público mediante eventos de ventana:
 *
 *   Entrada:  'mvs-scanner-open'          → abre la hoja del escáner.
 *   Salida:   'mvs-scan'                  → { code, source: 'camera' } por cada
 *                                           lectura válida de código de producto.
 *             'mvs-scanner-change'        → { open: boolean } al abrir/cerrar.
 *
 * El consumidor (POS ahora; inventario en R03) decide qué hacer con el código:
 * aquí no hay carrito, búsquedas ni lógica de negocio.
 */

import {
    CameraScanEngine,
    describeCameraError,
    scannerProbablyAvailable,
} from './engine';

document.addEventListener('alpine:init', () => {

    window.Alpine.data('mvsScanner', () => ({

        open: false,
        starting: false,
        error: '',
        status: '',
        canToggleCamera: false,

        _engine: null,

        init() {
            this._onScan = result => this._handleScan(result);
            this._onError = message => { this.error = message; };
            this._onVisibility = () => {
                // Al ocultar la pestaña se libera la cámara de inmediato.
                if (document.hidden && this.open) this.close();
            };
            document.addEventListener('visibilitychange', this._onVisibility);
        },

        destroy() {
            document.removeEventListener('visibilitychange', this._onVisibility);
            if (this._engine) {
                this._engine.destroy();
                this._engine = null;
            }
        },

        async openScanner(videoId) {
            if (this.open) return;
            this.open = true;
            this.error = '';
            this.status = '';
            this.canToggleCamera = false;
            window.dispatchEvent(new CustomEvent('mvs-scanner-change', { detail: { open: true } }));
            await this.$nextTick();

            if (!scannerProbablyAvailable()) {
                this.error = 'Este navegador no admite el uso de la cámara. Puede seguir usando la búsqueda manual o un lector de códigos.';
                return;
            }

            const video = document.getElementById(videoId || 'pos-scanner-video');
            if (!video) {
                this.error = 'No fue posible iniciar la cámara. Intente nuevamente.';
                return;
            }

            this.starting = true;
            try {
                this._engine = new CameraScanEngine({ video, onScan: this._onScan, onError: this._onError });
                await this._engine.start();
                this.canToggleCamera = this._engine.canSwitchCamera;
                this.status = 'Apunte la cámara al código de barras o QR del producto.';
            } catch (error) {
                this.error = describeCameraError(error);
                this.status = '';
            } finally {
                this.starting = false;
            }
        },

        async toggleCamera() {
            if (!this._engine || !this._engine.canSwitchCamera || this.starting) return;
            this.starting = true;
            this.error = '';
            try {
                await this._engine.switchCamera();
                this.status = this._engine.facingMode === 'environment'
                    ? 'Cámara trasera activa. Apunte al código del producto.'
                    : 'Cámara frontal activa.';
            } catch (error) {
                this.error = describeCameraError(error);
            } finally {
                this.starting = false;
            }
        },

        close() {
            if (!this.open) return;
            document.removeEventListener('visibilitychange', this._onVisibility);
            if (this._engine) {
                this._engine.destroy();
                this._engine = null;
            }
            this.open = false;
            this.error = '';
            this.status = '';
            this.canToggleCamera = false;
            window.dispatchEvent(new CustomEvent('mvs-scanner-change', { detail: { open: false } }));
        },

        _handleScan(result) {
            // El motor entrega un único evento por lectura válida y ya liberó la cámara.
            window.dispatchEvent(new CustomEvent('mvs-scan', {
                detail: { code: String(result?.code ?? '').trim(), source: result?.source || 'camera' },
            }));
            // Una lectura válida cierra el escáner; el consumidor resuelve la búsqueda.
            this.close();
        },

    }));

});

// Disponibilidad para que las vistas decidan si muestran el acceso a cámara.
if (typeof window !== 'undefined') {
    window.mvsScannerAvailable = scannerProbablyAvailable();
}
