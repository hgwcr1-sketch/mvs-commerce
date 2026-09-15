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
 * Fallback: si QZ Tray no está disponible, el flujo actual de impresión del
 * navegador (window.print) sigue intacto; esta vista solo muestra el estado.
 */

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
                    : 'No fue posible imprimir directamente.';
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

        check() {
            this.connected = null;
            this.message = 'Verificando MVS Print…';

            try {
                if (typeof window.qz !== 'undefined' && window.qz?.websocket) {
                    this.configureSecurity();
                    window.qz.websocket.connect()
                        .then(() => {
                            this.connected = true;
                            this.message = 'MVS Print conectado en este equipo.';
                        })
                        .catch(() => {
                            this.connected = false;
                            this.message = 'MVS Print está instalado pero no responde. Verifique que la aplicación esté abierta.';
                        });
                } else {
                    this.connected = false;
                    this.message = 'MVS Print no está disponible. La impresión del navegador sigue funcionando.';
                }
            } catch (error) {
                this.connected = false;
                this.message = 'No se pudo comprobar MVS Print: ' + error.message;
            }
        },

        listPrinters() {
            if (!this.connected || typeof window.qz === 'undefined') {
                this.message = 'MVS Print no está conectado.';
                return;
            }
            this.message = 'Consultando impresoras del sistema…';
            try {
                window.qz.printers.find()
                    .then((printers) => {
                        this.printers = printers ?? [];
                        this.message = this.printers.length > 0
                            ? 'Impresoras encontradas: ' + this.printers.join(', ')
                            : 'No se encontraron impresoras en este equipo.';
                    })
                    .catch((error) => {
                        this.message = 'No se pudieron listar las impresoras: ' + error.message;
                    });
            } catch (error) {
                this.message = 'No se pudieron listar las impresoras: ' + error.message;
            }
        },

        testPrint(terminalId) {
            if (this.busy) {
                return;
            }
            if (!this.connected || typeof window.qz === 'undefined') {
                this.message = 'MVS Print no está conectado. No se puede imprimir la prueba.';
                return;
            }
            if (!this.testUrl) {
                this.message = 'No hay URL de impresión de prueba en esta vista.';
                return;
            }
            this.busy = true;
            this.message = 'Preparando impresión de prueba…';

            fetch(this.testUrl.replace('__ID__', terminalId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.success) {
                        this.message = data.message ?? 'No se pudo generar el ticket de prueba.';
                        return;
                    }
                    const printer = data.printer || this.printerName;
                    if (!printer) {
                        this.message = 'Seleccione una impresora (o asígnela a la terminal) antes de imprimir.';
                        return;
                    }
                    this.sendToQz(printer, data.payload);
                })
                .catch((error) => {
                    this.message = 'Error al preparar la impresión: ' + error.message;
                })
                .finally(() => {
                    this.busy = false;
                });
        },

        /**
         * Abre el cajón de forma independiente.
         * Funciona sin importar si "Abrir cajón después de venta" está activado.
         * El botón manual siempre está disponible cuando la terminal tiene cajón.
         */
        openDrawer(terminalId) {
            if (this.busy) {
                return;
            }
            if (!this.connected || typeof window.qz === 'undefined') {
                this.message = 'MVS Print no está conectado. No se puede abrir el cajón.';
                return;
            }
            if (!this.drawerUrl) {
                this.message = 'No hay URL de apertura de cajón en esta vista.';
                return;
            }
            this.busy = true;
            this.message = 'Abriendo cajón…';

            fetch(this.drawerUrl.replace('__ID__', terminalId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.success) {
                        this.message = data.message ?? 'No se pudo preparar la apertura del cajón.';
                        return;
                    }
                    const printer = data.printer || this.printerName;
                    if (!printer) {
                        this.message = 'Seleccione una impresora (o asígnela a la terminal) antes de abrir el cajón.';
                        return;
                    }
                    this.sendToQz(printer, data.payload);
                })
                .catch((error) => {
                    this.message = 'Error al abrir el cajón: ' + error.message;
                })
                .finally(() => {
                    this.busy = false;
                });
        },

        /**
         * Configura la firma del canal QZ según el flujo oficial (qz.io/docs/signing):
         * SHA512 + promise que firma el mensaje crudo "toSign" en el servidor.
         * Solo se activa cuando el servidor tiene certificado provisionado
         * (modo firmado); sin él, QZ Tray usa sus diálogos estándar de confirmación.
         */
        configureSecurity() {
            if (!this.signedMode || !this.signatureUrl || !this.certificateUrl) {
                return;
            }
            window.qz.security.setSignatureAlgorithm('SHA512');

            // Certificate promise: devuelve el certificado X509 público
            window.qz.security.setCertificatePromise(() => {
                return fetch(this.certificateUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'text/plain',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }).then((response) => {
                    if (!response.ok) {
                        return Promise.reject(new Error('HTTP ' + response.status));
                    }
                    return response.text();
                });
            });

            // Signature promise: firma el mensaje crudo "toSign" en el servidor
            window.qz.security.setSignaturePromise((toSign) => {
                return (resolve, reject) => {
                    fetch(this.signatureUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'text/plain',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ request: toSign }),
                    })
                        .then((response) => (response.ok ? response.text() : Promise.reject(new Error('HTTP ' + response.status))))
                        .then(resolve)
                        .catch(reject);
                };
            });
        },

        sendToQz(printer, payload) {
            try {
                const commands = this.buildEscPos(payload);
                const base64 = this.toBase64(commands);

                // API oficial QZ Tray 2.x: config por impresora + RAW base64.
                const config = window.qz.configs.create(printer, { copies: 1 });
                const printData = [
                    {
                        type: 'raw',
                        format: 'command',
                        flavor: 'base64',
                        data: base64,
                    },
                ];

                window.qz.print(config, printData).then(() => {
                    this.message = 'Ticket de prueba enviado a: ' + printer;
                }).catch((error) => {
                    this.message = 'No se pudo imprimir: ' + error.message;
                });
            } catch (error) {
                this.message = 'Error al enviar a impresión: ' + error.message;
            }
        },

        buildEscPos(payload) {
            const bytes = [];

            for (const line of payload.lines || []) {
                if (line.type === 'empty') {
                    bytes.push(0x1B, 0x64, 0x01); // ESC d 1 (feed)
                    continue;
                }
                if (line.type === 'separator') {
                    const w = parseInt(payload.paper_width, 10) || 80;
                    const sep = w === 58 ? '-'.repeat(16) : '-'.repeat(32);
                    this.pushText(bytes, sep);
                    bytes.push(0x0A);
                    continue;
                }
                if (line.type === 'text') {
                    const isDouble = line.size === 'double';
                    if (line.emphasized) {
                        bytes.push(0x1B, 0x45, 0x01); // ESC E 1 (emphasized)
                    }
                    if (isDouble) {
                        bytes.push(0x1D, 0x21, 0x11); // GS ! 0x11 double width+height
                    }
                    if (line.align === 'center') {
                        bytes.push(0x1B, 0x61, 0x01); // ESC a 1 (center)
                    } else if (line.align === 'right') {
                        bytes.push(0x1B, 0x61, 0x02); // ESC a 2 (right)
                    } else {
                        bytes.push(0x1B, 0x61, 0x00); // ESC a 0 (left)
                    }
                    this.pushText(bytes, line.value ?? '');
                    bytes.push(0x0A);
                    if (isDouble) {
                        bytes.push(0x1D, 0x21, 0x00); // GS ! 0x00 reset
                    }
                    if (line.emphasized) {
                        bytes.push(0x1B, 0x45, 0x00); // ESC E 0 (desactivar)
                    }
                }
                if (line.type === 'qr') {
                    const qrData = line.value ?? '';
                    const isDataUrl = qrData.startsWith('data:');
                    if (qrData && !isDataUrl) {
                        this.addQrCode(bytes, qrData, line.size ?? 'medium', line.align ?? 'center');
                    }
                }
            }

            bytes.push(0x1B, 0x64, 0x05); // ESC d 5 (feed hacia el corte)

            if (payload.auto_cut) {
                bytes.push(0x1D, 0x56, 0x42, 0x00); // GS V B 0 (full cut)
            }

            if (payload.open_drawer && payload.drawer_command) {
                bytes.push(...payload.drawer_command);
            }

            return bytes;
        },

        /**
         * Agrega comando QR Code ESC/POS (GS ( k)
         * Compatible con impresoras térmicas estándar (Epson, Star, etc.)
         */
        addQrCode(bytes, data, size = 'medium', align = 'center') {
            // Modelos de QR:
            // 49 (Model 1), 50 (Model 2 - default), 51 (Micro QR)
            // Tamaño: 1-16 (dots per module)
            const model = 50; // Model 2
            const sizeMap = { small: 3, medium: 4, large: 6 };
            const moduleSize = sizeMap[size] ?? 4;
            const errorCorrection = 48; // 48=L (7%), 49=M (15%), 50=Q (25%), 51=H (30%)

            const encoded = new TextEncoder().encode(data);
            const dataLen = encoded.length;
            const pL = dataLen & 0xFF;
            const pH = (dataLen >> 8) & 0xFF;

            // Alineación
            if (align === 'center') {
                bytes.push(0x1B, 0x61, 0x01); // ESC a 1 (center)
            } else if (align === 'right') {
                bytes.push(0x1B, 0x61, 0x02); // ESC a 2 (right)
            } else {
                bytes.push(0x1B, 0x61, 0x00); // ESC a 0 (left)
            }

            // GS ( k - Set QR code model
            bytes.push(0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x00, model);
            // GS ( k - Set QR code size
            bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, moduleSize);
            // GS ( k - Set QR code error correction
            bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, errorCorrection);
            // GS ( k - Store QR code data
            bytes.push(0x1D, 0x28, 0x6B, pL, pH, 0x31, 0x50, 0x30);
            bytes.push(...encoded);
            // GS ( k - Print QR code
            bytes.push(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30);

            // Feed después del QR
            bytes.push(0x1B, 0x64, 0x03); // ESC d 3
        },

        pushText(bytes, text) {
            for (const char of String(text)) {
                const code = char.charCodeAt(0);
                if (code < 256) {
                    bytes.push(code);
                } else {
                    const encoded = new TextEncoder().encode(char);
                    for (const byte of encoded) {
                        bytes.push(byte);
                    }
                }
            }
        },

        toBase64(byteArray) {
            let binary = '';
            for (const byte of byteArray) {
                binary += String.fromCharCode(byte);
            }
            return btoa(binary);
        },
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
            const response = await fetch(configUrl + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return { auto_print: false, terminal: null };
            return await response.json();
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
            const response = await fetch(ticketUrl.replace('{sale}', saleId) + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return null;
            return await response.json();
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
        if (typeof window.qz === 'undefined' || !window.qz?.websocket) {
            return { success: false, error: 'MVS Print no disponible' };
        }

        const ticketData = await this.fetchTicket(ticketUrl, saleId, options);
        if (!ticketData || !ticketData.success || !ticketData.payload) {
            return { success: false, error: 'No se pudo obtener el ticket' };
        }

        const printer = ticketData.printer || (!options.reprint && options.printerName);
        if (!printer) {
            return { success: false, error: 'No hay impresora configurada' };
        }

        // Configurar seguridad QZ con certificado y firma si está disponible
        const qzConfig = ticketData.qz;
        if (qzConfig?.signed_mode && qzConfig?.certificate_url && qzConfig?.signature_url) {
            this.configureQzSecurity(qzConfig.certificate_url, qzConfig.signature_url);
        }

        try {
            // QZ connect() resuelve sin valor y rechaza si ya existe conexión.
            if (!window.qz.websocket.isActive()) {
                await window.qz.websocket.connect();
            }
        } catch {
            return { success: false, error: 'MVS Print no responde' };
        }

        try {
            const payload = options.reprint
                ? { ...ticketData.payload, open_drawer: false }
                : ticketData.payload;
            const commands = this.buildEscPosFromPayload(payload);
            const base64 = this.toBase64(commands);
            const config = window.qz.configs.create(printer, { copies: 1 });
            const printData = [{ type: 'raw', format: 'command', flavor: 'base64', data: base64 }];

            // No declarar fallo mientras QZ espera autorización y aún puede imprimir.
            await window.qz.print(config, printData);

            return { success: true, printer };
        } catch (err) {
            return { success: false, error: err.message || 'Error al imprimir' };
        }
    },

    /**
     * Construye bytes ESC/POS desde un payload del backend.
     * Misma lógica que buildEscPos en el componente Alpine, pero independiente.
     */
    buildEscPosFromPayload(payload) {
        const bytes = [];

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
                    this.addQrCode(bytes, qrData, line.size ?? 'medium', line.align ?? 'center');
                }
            }
        }

        bytes.push(0x1B, 0x64, 0x05); // ESC d 5 (feed hacia el corte)

        if (payload.auto_cut) {
            bytes.push(0x1D, 0x56, 0x42, 0x00); // GS V B 0 (full cut)
        }

        if (payload.open_drawer && payload.drawer_command) {
            bytes.push(...payload.drawer_command);
        }

        return bytes;
    },

    /**
     * Agrega comando QR Code ESC/POS (GS ( k)
     * Compatible con impresoras térmicas estándar (Epson, Star, etc.)
     */
    addQrCode(bytes, data, size = 'medium', align = 'center') {
        const model = 50; // Model 2
        const sizeMap = { small: 3, medium: 4, large: 6 };
        const moduleSize = sizeMap[size] ?? 4;
        const errorCorrection = 48; // L (7%)

        const encoded = new TextEncoder().encode(data);
        const dataLen = encoded.length;
        const pL = dataLen & 0xFF;
        const pH = (dataLen >> 8) & 0xFF;

        if (align === 'center') {
            bytes.push(0x1B, 0x61, 0x01);
        } else if (align === 'right') {
            bytes.push(0x1B, 0x61, 0x02);
        } else {
            bytes.push(0x1B, 0x61, 0x00);
        }

        bytes.push(0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x00, model);
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
            if (code < 256) {
                bytes.push(code);
            } else {
                const encoded = new TextEncoder().encode(char);
                for (const byte of encoded) {
                    bytes.push(byte);
                }
            }
        }
    },

    toBase64(byteArray) {
        let binary = '';
        for (const byte of byteArray) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary);
    },

    /**
     * Configura la seguridad QZ (certificado + firma) para impresión silenciosa.
     * Debe llamarse antes de window.qz.print().
     */
    configureQzSecurity(certificateUrl, signatureUrl) {
        if (typeof window.qz === 'undefined' || !window.qz?.security) {
            return;
        }
        window.qz.security.setSignatureAlgorithm('SHA512');

        // Certificate promise: devuelve el certificado X509 público
        window.qz.security.setCertificatePromise(() => {
            return fetch(certificateUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'text/plain',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then((response) => {
                if (!response.ok) {
                    return Promise.reject(new Error('HTTP ' + response.status));
                }
                return response.text();
            });
        });

        // Signature promise: firma el mensaje crudo "toSign" en el servidor
        window.qz.security.setSignaturePromise((toSign) => {
            return fetch(signatureUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/plain',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ request: toSign }),
            }).then((response) => {
                if (!response.ok) {
                    return Promise.reject(new Error('HTTP ' + response.status));
                }
                return response.text();
            });
        });
    },
};
