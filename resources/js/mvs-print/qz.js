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

            if (payload.open_drawer && payload.drawer_command) {
                bytes.push(...payload.drawer_command);
            }

            if (payload.auto_cut) {
                bytes.push(0x1D, 0x56, 0x42, 0x00); // GS V B 0 (full cut)
            }

            bytes.push(0x1B, 0x64, 0x05); // ESC d 5 (feed hacia el corte)

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