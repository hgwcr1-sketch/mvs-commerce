/**
 * R02-B — Motor de escaneo por cámara.
 *
 * Capa frontend reutilizable (POS ahora, inventario en R03) sin lógica
 * de negocio: detecta códigos localmente y entrega resultados al
 * consumidor mediante callbacks. Nunca envía imágenes ni códigos a
 * servicios externos.
 *
 * Detección progresiva:
 *   A. BarcodeDetector nativo cuando está disponible y soporta los formatos.
 *   B. Fallback local @zxing/library cargado por dynamic import (chunk aparte).
 */

export const SCAN_FORMATS = [
    'ean_13',
    'ean_8',
    'upc_a',
    'upc_e',
    'code_128',
    'code_39',
    'qr_code',
];

const COOLDOWN_MS = 1200;
const FRAME_INTERVAL_MS = 150;
const MAX_QR_LENGTH = 128;

export function hasCameraApi() {
    return typeof navigator !== 'undefined'
        && !!navigator.mediaDevices
        && typeof navigator.mediaDevices.getUserMedia === 'function';
}

export function isSecureForCamera() {
    return typeof window !== 'undefined' && window.isSecureContext === true;
}

/**
 * Indica si conviene mostrar el acceso a la cámara:
 * navegadores con API disponible o contexto HTTP inseguro
 * (para poder explicar el requisito de HTTPS al intentar usarla).
 */
export function scannerProbablyAvailable() {
    if (!hasCameraApi() && !isSecureForCamera()) {
        const insecureHttp = typeof window !== 'undefined' && window.location.protocol === 'http:';
        return insecureHttp;
    }
    return true;
}

export async function isBarcodeDetectorUsable() {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) return false;
    try {
        const supported = await window.BarcodeDetector.getSupportedFormats();
        return SCAN_FORMATS.some(format => supported.includes(format));
    } catch (error) {
        return false;
    }
}

/**
 * Decide si un texto leído puede tratarse como código de producto.
 *
 * Los códigos de barras de producto (EAN/UPC/Code*) ya vienen validados por
 * su formato; esta verificación aplica sobre todo a QR:
 *  - Rechaza URLs y esquemas (portal F33/F34, links arbitrarios).
 *  - Rechaza textos con espacios u otros caracteres improbables en un código.
 */
export function isProductCodeText(text) {
    const value = String(text ?? '').trim();
    if (!value || value.length > MAX_QR_LENGTH) return false;
    if (/^[a-z][a-z0-9+.-]*:/i.test(value)) return false;
    if (value.includes('://')) return false;
    if (/^www\./i.test(value)) return false;
    if (!/^[A-Za-z0-9\-_.]+$/.test(value)) return false;
    return true;
}

export function describeCameraError(error) {
    const name = error?.name || '';
    if (error?.kind === 'insecure' || (!isSecureForCamera() && !hasCameraApi())) {
        return 'La cámara requiere una conexión segura (HTTPS). El POS sigue funcionando con búsqueda manual, teclado o lector.';
    }
    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Permiso de cámara denegado. Habilite la cámara para este sitio e intente nuevamente.';
        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return 'No se encontró una cámara disponible en este dispositivo.';
        case 'NotReadableError':
        case 'TrackStartError':
            return 'La cámara está siendo usada por otra aplicación. Cierre esa aplicación e intente nuevamente.';
        case 'OverconstrainedError':
            return 'No fue posible abrir la cámara solicitada en este dispositivo.';
        default:
            if (error?.kind === 'unsupported') {
                return 'Este navegador no admite el uso de la cámara. Puede seguir usando la búsqueda manual o un lector de códigos.';
            }
            if (error?.kind === 'detector') {
                return 'Este navegador no permite leer códigos con la cámara.';
            }
            return 'No fue posible iniciar la cámara. Intente nuevamente.';
    }
}

function cameraError(kind, name) {
    const error = new Error(kind);
    error.kind = kind;
    if (name) error.name = name;
    return error;
}

export class CameraScanEngine {
    /**
     * @param {{ video: HTMLVideoElement, onScan: Function, onError: Function }} options
     */
    constructor({ video, onScan, onError }) {
        this._video = video;
        this._onScan = onScan;
        this._onError = onError;
        this._stream = null;
        this._detector = null;
        this._zxingReader = null;
        this._canvas = null;
        this._timer = null;
        this._active = false;
        this._lastRawValue = null;
        this._lastReadAt = 0;
        this._facingMode = 'environment';
        this._canSwitch = false;
        this._onVisibility = () => {
            if (document.hidden) this.stop();
        };
    }

    get canSwitchCamera() {
        return this._canSwitch;
    }

    get facingMode() {
        return this._facingMode;
    }

    async start() {
        if (!isSecureForCamera()) throw cameraError('insecure');
        if (!hasCameraApi()) throw cameraError('unsupported');

        this._stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: this._facingMode } },
            audio: false,
        });

        this._video.srcObject = this._stream;
        try {
            await this._video.play();
        } catch (error) {
            // Autoplay puede diferirse hasta que haya fotogramas; no es fatal.
        }

        await this._prepareDetector();

        const devices = await navigator.mediaDevices.enumerateDevices();
        this._canSwitch = devices.filter(device => device.kind === 'videoinput').length > 1;

        this._active = true;
        document.addEventListener('visibilitychange', this._onVisibility);
        this._scheduleNextFrame(FRAME_INTERVAL_MS);
    }

    async _prepareDetector() {
        if (await isBarcodeDetectorUsable()) {
            const supported = await window.BarcodeDetector.getSupportedFormats();
            const formats = SCAN_FORMATS.filter(format => supported.includes(format));
            this._detector = new window.BarcodeDetector({ formats });
            this._detectFrame = () => this._detectNative();
            return;
        }
        const { BrowserMultiFormatReader, DecodeHintType, BarcodeFormat } = await import('@zxing/library');
        const hints = new Map();
        hints.set(DecodeHintType.POSSIBLE_FORMATS, [
            BarcodeFormat.EAN_13,
            BarcodeFormat.EAN_8,
            BarcodeFormat.UPC_A,
            BarcodeFormat.UPC_E,
            BarcodeFormat.CODE_128,
            BarcodeFormat.CODE_39,
            BarcodeFormat.QR_CODE,
        ]);
        hints.set(DecodeHintType.TRY_HARDER, true);
        this._zxingReader = new BrowserMultiFormatReader(hints);
        this._zxingFormats = BarcodeFormat;
        this._detectFrame = () => this._detectWithZxing();
    }

    async _detectNative() {
        if (!this._detector) return [];
        try {
            return await this._detector.detect(this._video);
        } catch (error) {
            return [];
        }
    }

    _detectWithZxing() {
        if (!this._zxingReader) return [];
        try {
            if (!this._canvas) this._canvas = document.createElement('canvas');
            const width = this._video.videoWidth;
            const height = this._video.videoHeight;
            if (!width || !height) return [];
            const scale = Math.min(1, 960 / width);
            this._canvas.width = Math.round(width * scale);
            this._canvas.height = Math.round(height * scale);
            const context = this._canvas.getContext('2d');
            context.drawImage(this._video, 0, 0, this._canvas.width, this._canvas.height);
            const result = this._zxingReader.decodeFromCanvas(this._canvas);
            return [{
                rawValue: result.getText(),
                format: this._formatName(result.getBarcodeFormat()),
            }];
        } catch (error) {
            // NotFoundException u otro: ningún código en este fotograma.
            return [];
        }
    }

    _formatName(zxingFormat) {
        const name = this._zxingFormats ? this._zxingFormats[zxingFormat] : '';
        return name ? String(name).toLowerCase() : '';
    }

    _scheduleNextFrame(delay) {
        if (!this._active) return;
        this._timer = setTimeout(() => { this._tick(); }, delay);
    }

    async _tick() {
        if (!this._active) return;
        let results = [];
        try {
            results = (await this._detectFrame()) || [];
        } catch (error) {
            results = [];
        }
        // El escáner pudo detenerse mientras se procesaba el fotograma.
        if (!this._active) return;

        for (const result of results) {
            const rawValue = String(result.rawValue ?? '').trim();
            if (!rawValue) continue;

            const now = Date.now();
            if (rawValue === this._lastRawValue && now - this._lastReadAt < COOLDOWN_MS) continue;
            this._lastRawValue = rawValue;
            this._lastReadAt = now;

            const format = String(result.format ?? '');
            if (format === 'qr_code' && !isProductCodeText(rawValue)) {
                // QR de URL/token/cliente/fidelización: nunca se busca como producto.
                this._onError('El código QR leído no corresponde a un producto. Apunte a un código de barras o QR de producto.');
                continue;
            }
            if (!format && !isProductCodeText(rawValue)) continue;

            // Lectura válida: un solo evento, pausa total y liberación de cámara.
            this.stop();
            this._onScan({ code: rawValue, source: 'camera' });
            return;
        }

        this._scheduleNextFrame(FRAME_INTERVAL_MS);
    }

    async switchCamera() {
        this._facingMode = this._facingMode === 'environment' ? 'user' : 'environment';
        const wasActive = this._active;
        this._releaseStream();
        if (!wasActive) return;
        await this.start();
    }

    stop() {
        this._active = false;
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
        document.removeEventListener('visibilitychange', this._onVisibility);
        this._releaseStream();
    }

    _releaseStream() {
        if (this._stream) {
            this._stream.getTracks().forEach(track => track.stop());
            this._stream = null;
        }
        if (this._video) {
            this._video.pause();
            this._video.srcObject = null;
        }
    }

    destroy() {
        this.stop();
        this._video = null;
        this._onScan = null;
        this._onError = null;
        this._detector = null;
        this._zxingReader = null;
        this._zxingFormats = null;
        this._canvas = null;
    }
}
