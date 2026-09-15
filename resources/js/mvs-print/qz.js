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
 * Carga de qz-tray.js (v2.2.6):
 *  QZ Tray instala localmente el archivo qz-tray.js que expone el objeto
 *  global `window.qz`. Cuando QZ Tray está corriendo, el archivo se sirve
 *  desde https://localhost:8181/qz-tray.js (WebSocket seguro). La página
 *  debe incluir una etiqueta <script> apuntando a esa URL o copiar el archivo
 *  desde el directorio de instalación de QZ Tray (C:\Program Files\QZ Tray\js\).
 *  NO se sirve desde el servidor Laravel ni desde Vite.
 *
 * Fallback: si QZ Tray no está disponible, el flujo actual de impresión del
 * navegador (window.print) sigue intacto; esta vista solo muestra el estado.
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('mvsPrintQz', (initialState = {}) => ({
        connected: null,
        message: '',
        busy: false,
        printerName: '',
        printers: [],
        testUrl: initialState.testUrl || null,
        drawerUrl: initialState.drawerUrl || null,
        signatureUrl: initialState.signatureUrl || null,
        signedMode: initialState.signedMode || false,

        init() {
            this.check();
        },

        check() {
            this.connected = null;
            this.message = 'Consultando el puente local de QZ Tray…';

            try {
                if (typeof window.qz !== 'undefined' && window.qz?.websocket) {
                    this.configureSecurity();
                    window.qz.websocket.connect()
                        .then(() => {
                            this.connected = true;
                            this.message = 'QZ Tray conectado en este equipo.';
                        })
                        .catch(() => {
                            this.connected = false;
                            this.message = 'QZ Tray está instalado pero no responde. Verifique que la aplicación esté abierta.';
                        });
                } else {
                    this.connected = false;
                    this.message = 'QZ Tray no está instalado o la librería local no está cargada. La impresión del navegador sigue funcionando.';
                }
            } catch (error) {
                this.connected = false;
                this.message = 'No se pudo comprobar QZ Tray: ' + error.message;
            }
        },

        listPrinters() {
            if (!this.connected || typeof window.qz === 'undefined') {
                this.message = 'QZ Tray no está conectado.';
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
                this.message = 'QZ Tray no está conectado. No se puede imprimir la prueba.';
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
                this.message = 'QZ Tray no está conectado. No se puede abrir el cajón.';
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
            if (!this.signedMode || !this.signatureUrl) {
                return;
            }
            window.qz.security.setSignatureAlgorithm('SHA512');
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
                this.message = 'Fallo al enviar a QZ Tray: ' + error.message;
            }
        },

        buildEscPos(payload) {
            const bytes = [];

            for (const line of payload.lines || []) {
                if (line.type === 'empty') {
                    bytes.push(0x1B, 0x64, 0x01); // ESC d 1 (feed)
                    continue;
                }
                if (line.type === 'text') {
                    if (line.emphasized) {
                        bytes.push(0x1B, 0x45, 0x01); // ESC E 1 (emphasized)
                    }
                    if (line.align === 'center') {
                        bytes.push(0x1B, 0x61, 0x01); // ESC a 1 (center)
                    } else {
                        bytes.push(0x1B, 0x61, 0x00); // ESC a 0 (left)
                    }
                    this.pushText(bytes, line.value ?? '');
                    bytes.push(0x0A);
                    if (line.emphasized) {
                        bytes.push(0x1B, 0x45, 0x00); // ESC E 0 (desactivar)
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
    async fetchTicket(ticketUrl, saleId) {
        const terminalUuid = this.getTerminalUuid();
        const params = terminalUuid ? `?terminal_uuid=${encodeURIComponent(terminalUuid)}` : '';
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
        const timeout = options.timeout || 5000;

        if (typeof window.qz === 'undefined' || !window.qz?.websocket) {
            return { success: false, error: 'QZ Tray no disponible' };
        }

        try {
            const connected = await Promise.race([
                window.qz.websocket.connect(),
                new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), timeout)),
            ]);
            if (!connected) {
                return { success: false, error: 'QZ Tray no responde' };
            }
        } catch {
            return { success: false, error: 'QZ Tray no responde' };
        }

        const ticketData = await this.fetchTicket(ticketUrl, saleId);
        if (!ticketData || !ticketData.success || !ticketData.payload) {
            return { success: false, error: 'No se pudo obtener el ticket' };
        }

        const printer = ticketData.printer || options.printerName;
        if (!printer) {
            return { success: false, error: 'No hay impresora configurada' };
        }

        try {
            const payload = ticketData.payload;
            const commands = this.buildEscPosFromPayload(payload);
            const base64 = this.toBase64(commands);
            const config = window.qz.configs.create(printer, { copies: 1 });
            const printData = [{ type: 'raw', format: 'command', flavor: 'base64', data: base64 }];

            await Promise.race([
                window.qz.print(config, printData),
                new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), timeout)),
            ]);

            return { success: true };
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
                if (line.emphasized) {
                    bytes.push(0x1B, 0x45, 0x01);
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
                if (line.emphasized) {
                    bytes.push(0x1B, 0x45, 0x00);
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
};