/**
 * MVS Print — puente con QZ Tray para impresión local.
 *
 * Responsabilidades (solo frontend/local):
 *  - Detectar el puente QZ Tray en este equipo (conectado/desconectado).
 *  - Listar impresoras locales disponibles (qz.printers.find() — sin endpoint Laravel).
 *  - Enviar el ticket de prueba ESC/POS (corte y cajón configurables).
 *  - Abrir el cajón de forma independiente (botón manual).
 *
 * El backend solo:
 *  - Guarda la configuración de la terminal (impresora, ancho, corte, cajón).
 *  - Devuelve el payload de prueba o de apertura de cajón.
 *  - Firma las peticiones de QZ Tray vía /mvs/print/signature
 *    (clave privada jamás sale del servidor).
 *
 * Librería QZ:
 *  La librería cliente qz-tray.js (v2.2.6) se incluye como dependencia npm
 *  y se bundea con Vite. Queda disponible como window.qz.
 *  QZ Tray instalado localmente es el puente WebSocket que recibe los
 *  comandos RAW e impacta la impresora.
 *
*  Fallback: si QZ Tray no está disponible, el flujo actual de impresión del
 *  navegador (window.print) sigue intacto; esta vista solo muestra el estado.
 *
 *  Codificación del texto:
 *  El ticket se imprime en código de página CP850 (secuencia inicial ESC t 02).
 *  El byte 0x9B es ¢ en CP437 y en CP850, por lo que el colón costarricense
 *  (₡, U+20A1) se traduce a ese byte y nunca se envían bytes UTF-8 crudos al
 *  ticket: de esa forma la POS-58-Series no puede mostrar mojibake.
 */

// Tabla Unicode -> byte CP850 para los caracteres latinos del ticket.
const CP850_TEXT = {
    'Ç': 0x80, 'ü': 0x81, 'é': 0x82, 'â': 0x83, 'ä': 0x84, 'à': 0x85, 'å': 0x86, 'ç': 0x87,
    'ê': 0x88, 'ë': 0x89, 'è': 0x8A, 'ï': 0x8B, 'î': 0x8C, 'ì': 0x8D, 'Ä': 0x8E, 'Å': 0x8F,
    'É': 0x90, 'æ': 0x91, 'Æ': 0x92, 'ô': 0x93, 'ö': 0x94, 'ò': 0x95, 'û': 0x96, 'ù': 0x97,
    'ÿ': 0x98, 'Ö': 0x99, 'Ü': 0x9A, '¢': 0x9B, '£': 0x9C, '¥': 0x9D, 'ƒ': 0x9F,
    'á': 0xA0, 'í': 0xA1, 'ó': 0xA2, 'ú': 0xA3, 'ñ': 0xA4, 'Ñ': 0xA5, 'ª': 0xA6, 'º': 0xA7,
    '¿': 0xA8, '¬': 0xA9, '½': 0xAA, '¼': 0xAB, '¡': 0xAC, '«': 0xAD, '»': 0xAE,
    'Á': 0xB4, 'Â': 0xB5, 'À': 0xB6, 'ã': 0xC5, 'Ã': 0xC6, 'Ê': 0xD2, 'Ë': 0xD3, 'È': 0xD4,
    'Í': 0xD6, 'Î': 0xD7, 'Ï': 0xD8, 'Ì': 0xDE, 'Ó': 0xE0, 'ß': 0xE1, 'Ô': 0xE2, 'Ò': 0xE3,
    'õ': 0xE4, 'Õ': 0xE5, 'µ': 0xE6, 'Ú': 0xE9, 'Û': 0xEA, 'Ù': 0xEB, 'ý': 0xEC, 'Ý': 0xED,
    '₡': 0x9B,
};

// Capacidad en bytes del nivel L para versiones QR 1..40 (Modelo 2).
const QR_BYTE_CAPACITY_L = [19, 34, 55, 80, 108, 136, 156, 194, 232, 274, 324, 370, 428, 461,
    523, 589, 647, 721, 795, 861, 932, 1006, 1094, 1174, 1276, 1370, 1468, 1531, 1631, 1735,
    1843, 1955, 2071, 2191, 2306, 2434, 2566, 2702, 2812, 2956];

document.addEventListener('alpine:init', () => {
    Alpine.data('mvsReprint', (ticketUrl, saleId) => ({
        busy: false,
        failed: false,
        message: '',
        async reprint() {
            if (this.busy) return;
            this.busy = true;
            this.failed = false;
            this.message = 'Enviando factura…';
            try {
                const result = await window.MvsPrint.printSale(ticketUrl, saleId, { reprint: true });
                this.failed = !result.success;
                this.message = result.success
                    ? 'Factura enviada a ' + result.printer
                    : (result.error || 'No fue posible imprimir directamente.');
            } catch {
                this.failed = true;
                this.message = 'No fue posible imprimir directamente.';
            } finally {
                this.busy = false;
            }
        },
    }));

    Alpine.data('mvsPrintQz', (initialState = {}) => ({
        connected: null,
        message: '',
        busy: false,
        printerName: '',
        printers: [],
        testUrl: initialState.testUrl || null,
        drawerUrl: initialState.drawerUrl || null,
        signatureUrl: initialState.signatureUrl || null,
        certificateUrl: initialState.certificateUrl || null,
        signedMode: initialState.signedMode || false,

        init() {
            this.check();
        },

        checking: false,
        async check() {
            if (this.checking || this.busy) return;
            this.checking = true;
            this.connected = null;
            this.message = 'Verificando MVS Print…';
            try {
                await window.MvsPrint.ensureConnection(this.securityConfig());
                this.connected = true;
                this.message = 'MVS Print conectado en este equipo.';
            } catch (error) {
                this.connected = false;
                this.message = 'MVS Print no está conectado. ' + error.message;
            } finally {
                this.checking = false;
            }
        },
        securityConfig() {
            return { signed_mode: this.signedMode, certificate_url: this.certificateUrl, signature_url: this.signatureUrl };
        },
        async listPrinters() {
            if (this.busy || this.checking) return;
            this.busy = true;
            this.message = 'Consultando impresoras del sistema…';
            try {
                await window.MvsPrint.ensureConnection(this.securityConfig());
                this.printers = await window.MvsPrint.deadline(() => window.qz.printers.find(), 'Consultar impresoras');
                this.message = this.printers.length ? 'Impresoras encontradas: ' + this.printers.join(', ') : 'No se encontraron impresoras en este equipo.';
            } catch (error) {
                this.message = error.message;
            } finally {
                this.busy = false;
            }
        },
        async testPrint(terminalId) {
            return this.runPayload(this.testUrl, terminalId, false);
        },
        async openDrawer(terminalId) {
            return this.runPayload(this.drawerUrl, terminalId, true);
        },
        async runPayload(url, terminalId, drawer) {
            if (this.busy || this.checking) return;
            this.busy = true;
            this.message = drawer ? 'Abriendo cajón…' : 'Preparando impresión de prueba…';
            try {
                if (!url) throw new Error('No hay URL configurada.');
                const data = await window.MvsPrint.request(url.replace('__ID__', terminalId));
                if (!data.success) throw new Error(data.message || 'No se pudo preparar la impresión.');
                await window.MvsPrint.ensureConnection(this.securityConfig());
                await this.sendToQz(data.printer || this.printerName, data.payload);
                this.message = drawer ? 'Comando de cajón enviado.' : 'Ticket de prueba enviado a: ' + (data.printer || this.printerName);
            } catch (error) {
                this.message = error.message;
            } finally {
                this.busy = false;
            }
        },
        configureSecurity() {
            return window.MvsPrint.configureQzSecurity(this.certificateUrl, this.signatureUrl);
        },
        async sendToQz(printer, payload) {
            return window.MvsPrint.sendPayload(printer, payload);
        },

        buildEscPos(payload) { return window.MvsPrint.buildEscPosFromPayload(payload); },
        addQrCode(...args) { return window.MvsPrint.addQrCode(...args); },
        pushText(...args) { return window.MvsPrint.pushText(...args); },
        toBase64(bytes) { return window.MvsPrint.toBase64(bytes); },
    }));
});

/**
 * MVS Print — utilidades de impresión automática para el POS.
 *
 * Estas funciones se usan desde el checkout del POS para intentar
 * impresión automática después de una venta exitosa.
 * Son independientes del componente Alpine de configuración.
 */
window.MvsPrint = {
    STORAGE_KEY: 'mvs_print_terminal_uuid',

    getTerminalUuid() {
        try {
            return localStorage.getItem(this.STORAGE_KEY) || null;
        } catch {
            return null;
        }
    },

    setTerminalUuid(uuid) {
        try {
            localStorage.setItem(this.STORAGE_KEY, uuid);
        } catch {
            // localStorage no disponible — sin efecto.
        }
    },

    ensureTerminalUuid() {
        let uuid = this.getTerminalUuid();
        if (!uuid) {
            uuid = crypto.randomUUID ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = Math.random() * 16 | 0;
                    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            this.setTerminalUuid(uuid);
        }
        return uuid;
    },

    /**
     * Consulta la configuración de la terminal en el backend.
     * Retorna { auto_print, terminal: { ... } | null }
     */
    async fetchConfig(configUrl) {
        const terminalUuid = this.getTerminalUuid();
        const params = terminalUuid ? `?terminal_uuid=${encodeURIComponent(terminalUuid)}` : '';
        try {
            return await this.request(configUrl + params);
        } catch {
            return { auto_print: false, terminal: null };
        }
    },

    /**
     * Obtiene el payload ESC/POS de una venta ya completada.
     * Retorna { success, sale_id, sale_number, printer, payload }
     */
    async fetchTicket(ticketUrl, saleId, options = {}) {
        const terminalUuid = this.getTerminalUuid();
        const query = new URLSearchParams();
        if (terminalUuid) query.set('terminal_uuid', terminalUuid);
        if (options.reprint) query.set('reprint', '1');
        const params = query.size ? `?${query}` : '';
        try {
            return await this.request(ticketUrl.replace('{sale}', saleId) + params);
        } catch {
            return null;
        }
    },

    /**
     * Intenta imprimir una venta por QZ Tray.
     * Retorna { success: bool, error?: string }
     * NUNCA lanza excepción — el caller siempre recibe un resultado.
     */
    async printSale(ticketUrl, saleId, options = {}) {
        if (this._saleBusy) return { success: false, error: 'Ya hay una impresión en curso.' };
        this._saleBusy = true;
        try {
            const ticketData = await this.fetchTicket(ticketUrl, saleId, options);
            if (!ticketData?.success || !ticketData.payload) throw new Error('No se pudo obtener el ticket.');
            await this.ensureConnection(ticketData.qz);
            const printer = ticketData.printer || (!options.reprint && options.printerName);
            await this.sendPayload(printer, options.reprint ? { ...ticketData.payload, open_drawer: false } : ticketData.payload);
            return { success: true, printer };
        } catch (error) {
            return { success: false, error: error.message || 'Error al imprimir' };
        } finally {
            this._saleBusy = false;
        }
    },

    /**
     * Construye bytes ESC/POS desde un payload del backend.
     * Misma lógica que buildEscPos en el componente Alpine, pero independiente.
     */
    buildEscPosFromPayload(payload) {
        const bytes = [];

        // Drawer pulse FIRST — opens drawer before printing starts
        if (payload.open_drawer && payload.drawer_command) {
            bytes.push(...payload.drawer_command);
        }

        // Select CP850 so accented text and ¢ (0x9B) render natively.
        bytes.push(0x1B, 0x74, 0x02);

        for (const line of payload.lines || []) {
            if (line.type === 'empty' || line.type === 'separator') {
                if (line.type === 'separator') {
                    const w = parseInt(payload.paper_width, 10) || 80;
                    const sep = w === 58 ? '-'.repeat(16) : '-'.repeat(32);
                    this.pushText(bytes, sep);
                }
                bytes.push(0x0A);
                continue;
            }
            if (line.type === 'text') {
                const isDouble = line.size === 'double';
                if (line.emphasized) {
                    bytes.push(0x1B, 0x45, 0x01);
                }
                if (isDouble) {
                    bytes.push(0x1D, 0x21, 0x11);
                }
                if (line.align === 'center') {
                    bytes.push(0x1B, 0x61, 0x01);
                } else if (line.align === 'right') {
                    bytes.push(0x1B, 0x61, 0x02);
                } else {
                    bytes.push(0x1B, 0x61, 0x00);
                }
                this.pushText(bytes, line.value ?? '');
                bytes.push(0x0A);
                if (isDouble) {
                    bytes.push(0x1D, 0x21, 0x00);
                }
                if (line.emphasized) {
                    bytes.push(0x1B, 0x45, 0x00);
                }
            }
            if (line.type === 'qr') {
                // QR Code ESC/POS
                const qrData = line.value ?? '';
                // Filtrar data:image/... no imprimible como QR; usar solo URL plana
                const isDataUrl = qrData.startsWith('data:');
                if (qrData && !isDataUrl) {
                    this.addQrCode(bytes, qrData, line.size ?? 'medium', line.align ?? 'center', payload.paper_width);
                }
            }
        }

        bytes.push(0x1B, 0x64, 0x05); // ESC d 5 (feed hacia el corte)

        if (payload.auto_cut) {
            bytes.push(0x1D, 0x56, 0x42, 0x00); // GS V B 0 (full cut)
        }

        bytes.push(0x1B, 0x74, 0x00); // restore CP437 for subsequent jobs

        return bytes;
    },

    /**
     * Agrega comando QR Code ESC/POS (GS ( k)
     * Compatible con impresoras térmicas estándar (Epson, Star, etc.)
     *
     * En 58mm el tamaño de módulo es adaptativo: se calcula la versión QR
     * real del payload (nivel L) y se usa el módulo más grande que cabe en
     * el área imprimible (48mm ≈ 384 puntos) con su quiet zone. Para URLs
     * cortas se crece hasta ~38–40mm; si una versión grande no cabe con
     * módulo 5, se conserva 4 en vez de desbordar. En 80mm se mantiene el
     * tamaño configurado (medium → 4).
     */
    addQrCode(bytes, data, size = 'medium', align = 'center', paperWidth = '80') {
        const model = 50; // Model 2
        const sizeMap = { small: 3, medium: 4, large: 6 };
        let moduleSize = sizeMap[size] ?? 4;

        if (paperWidth === '58') {
            const length = new TextEncoder().encode(data).length;
            let version = 1;
            while (version < 40 && QR_BYTE_CAPACITY_L[version - 1] < length) {
                version++;
            }
            // Margen de una versión por si la impresora elige una superior.
            const buddy = Math.min(40, version + 1);
            const modules = 17 + 4 * buddy;
            const safeDots = 384; // 48mm * 8 puntos/mm
            const fit = Math.floor(safeDots / (modules + 8)); // quiet zone 4+4
            moduleSize = Math.min(8, Math.max(2, fit));
        }

        const errorCorrection = 48; // L (7%)

        const encoded = new TextEncoder().encode(data);
        const dataLen = encoded.length + 3;
        const pL = dataLen & 0xFF;
        const pH = (dataLen >> 8) & 0xFF;

        if (align === 'center') {
            bytes.push(0x1B, 0x61, 0x01);
        } else if (align === 'right') {
            bytes.push(0x1B, 0x61, 0x02);
        } else {
            bytes.push(0x1B, 0x61, 0x00);
        }

        bytes.push(0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, model, 0x00);
        bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, moduleSize);
        bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, errorCorrection);
        bytes.push(0x1D, 0x28, 0x6B, pL, pH, 0x31, 0x50, 0x30);
        bytes.push(...encoded);
        bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30);

        bytes.push(0x1B, 0x64, 0x03); // ESC d 3
    },

    pushText(bytes, text) {
        for (const char of String(text)) {
            const code = char.charCodeAt(0);
            if (code < 128) {
                bytes.push(code);
                continue;
            }
            const mapped = CP850_TEXT[char];
            if (mapped !== undefined) {
                bytes.push(mapped);
                continue;
            }
            // Nunca bytes UTF-8 crudos en el ticket: evita mojibake garantizado.
            bytes.push(0x3F); // '?'
        }
    },

    toBase64(byteArray) {
        let binary = '';
        for (const byte of byteArray) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary);
    },

    timeoutMs: 15000,
    printTimeoutMs: 45000,
    _qzSigned: false,
    _qzSignedUrls: null,
    _connecting: null,
    _pendingPrint: null,
    _epoch: 0,

    async deadline(operation, label, ms = this.timeoutMs, onTimeout = () => {}) {
        let timer;
        try {
            return await Promise.race([
                Promise.resolve().then(operation),
                new Promise((_, reject) => {
                    timer = setTimeout(() => {
                        onTimeout();
                        reject(new Error(label + ': tiempo de espera agotado.'));
                    }, ms);
                }),
            ]);
        } finally {
            clearTimeout(timer);
        }
    },
    async request(url, options = {}, format = 'json') {
        const controller = new AbortController();
        return this.deadline(async () => {
            const response = await fetch(url, {
                ...options, signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
            });
            if (!response.ok || response.redirected) throw new Error('MVS Print: HTTP ' + response.status);
            return format === 'text' ? response.text() : response.json();
        }, 'Solicitud MVS Print', this.timeoutMs, () => controller.abort());
    },
    async ensureConnection(config) {
        if (!window.qz?.websocket) throw new Error('Abra MVS Print y vuelva a detectar.');
        if (!config?.signed_mode || !config.certificate_url || !config.signature_url) {
            throw new Error('La configuración de firma MVS Print no está disponible.');
        }
        if (this._connecting) return this._connecting;
        const epoch = this._epoch;
        this._connecting = this.deadline(async () => {
            const signed = config?.signed_mode && config.certificate_url && config.signature_url;
            const key = signed ? config.certificate_url + '|' + config.signature_url : null;
            if (window.qz.websocket.isActive() && signed && (!this._qzSigned || this._qzSignedUrls !== key)) {
                await this.deadline(() => window.qz.websocket.disconnect(), 'Desconectar');
            }
            if (epoch !== this._epoch) throw new Error('Conexión cancelada.');
            if (signed) this.configureQzSecurity(config.certificate_url, config.signature_url);
            if (!window.qz.websocket.isActive()) await window.qz.websocket.connect();
            if (epoch !== this._epoch) {
                this.invalidateConnection();
                throw new Error('Conexión cancelada.');
            }
            this._qzSigned = !!signed;
        }, 'Conectar MVS Print', this.timeoutMs, () => this.invalidateConnection());
        try {
            return await this._connecting;
        } catch (error) {
            this.invalidateConnection();
            throw error;
        } finally {
            this._connecting = null;
        }
    },
    invalidateConnection() {
        this._epoch++;
        this._qzSigned = false;
        try { Promise.resolve(window.qz?.websocket.disconnect()).catch(() => {}); } catch {}
    },
    configureQzSecurity(certificateUrl, signatureUrl) {
        const key = certificateUrl + '|' + signatureUrl;
        if (this._qzSigned && this._qzSignedUrls === key && window.qz.websocket.isActive()) return;
        this._qzSignedUrls = key;
        const epoch = this._epoch;
        const current = value => {
            if (epoch !== this._epoch) throw new Error('Operación MVS Print cancelada.');
            return value;
        };
        // QZ 2.2.6 treats an ordinary function as a Promise executor.
        window.qz.security.setCertificatePromise((resolve, reject) => {
            this.request(certificateUrl, { headers: { Accept: 'text/plain' } }, 'text')
                .then(pem => {
                    if (!pem.includes('-----BEGIN CERTIFICATE-----') || !pem.includes('-----END CERTIFICATE-----')) throw new Error('Certificado MVS Print inválido.');
                    return current(pem);
                }).then(resolve, reject);
        }, { rejectOnFailure: true });
        window.qz.security.setSignatureAlgorithm('SHA512');
        window.qz.security.setSignaturePromise(toSign => (resolve, reject) => {
            this.request(signatureUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json', Accept: 'text/plain',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ request: toSign }),
            }, 'text').then(signature => {
                if (!/^[A-Za-z0-9+/]+={0,2}$/.test(signature)) throw new Error('Firma MVS Print inválida.');
                return current(signature);
            }).then(resolve, reject);
        });
    },
    async sendPayload(printer, payload) {
        if (!printer) throw new Error('No hay impresora configurada.');
        // An unresolved submitted job is not a UI lock. Never resubmit it after timeout.
        if (this._pendingPrint) throw new Error('No fue posible confirmar la impresión. Cierre MVS Print y revise la cola antes de reintentar.');
        const data = [{ type: 'raw', format: 'command', flavor: 'base64', data: this.toBase64(this.buildEscPosFromPayload(payload)) }];
        const pending = Promise.resolve().then(() => window.qz.print(window.qz.configs.create(printer, { copies: 1 }), data));
        this._pendingPrint = pending;
        pending.then(() => { if (this._pendingPrint === pending) this._pendingPrint = null; }, () => { if (this._pendingPrint === pending) this._pendingPrint = null; });
        try {
            await this.deadline(() => pending, 'Imprimir', this.printTimeoutMs, () => this.invalidateConnection());
        } catch (error) {
            if (error.message.includes('tiempo de espera')) throw new Error('No fue posible confirmar la impresión. Revise la impresora y la cola antes de reintentar o usar impresión del navegador.');
            throw error;
        }
    },
};
