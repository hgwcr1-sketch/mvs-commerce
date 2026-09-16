# MVS Print 1.0.2

Launcher propio de MVS Commerce para Windows x64. QZ Tray 2.2.6 conserva sus binarios, identidad y licencias como motor compartido en Program Files/QZ Tray.

## Instalación

El wrapper NSIS propone automáticamente `C:\Program Files\MVS Print`, crea la carpeta e instala `MVS Print.exe`, certificado público `.crt`, licencias, versión y desinstalador. Los accesos de Inicio y Escritorio apuntan al launcher propio. Reutiliza QZ 2.2.6; si falta lo instala silenciosamente. Otra versión requiere aceptación explícita y no se reemplaza; en modo silencioso se rechaza esa decisión.

La instalación configura únicamente la confianza del certificado público MVS, conserva otras raíces y usa los comandos oficiales QZ `--allow` y `--steal` para recargarla. Finalice impresiones antes de actualizar. Un fallo de configuración o del websocket impide registrar instalación completa. Desinstalar elimina archivos/accesos/registro propios y retira la raíz MVS; conserva QZ compartido, su inicio automático y las autorizaciones QZ existentes.

## Launcher

C# WinExe x64, compilado con el compilador .NET Framework de Windows; no distribuye Electron, Python, Node ni otro framework. Requiere Windows con .NET Framework 4.x y ClientWebSocket (Windows 10/11). Ejecuta como usuario normal, con mutex por sesión, detección de proceso y consulta de solo lectura getVersion por websocket loopback. No imprime ni abre cajón. Un motor existente que no responde produce error y no se duplica. `--verify` verifica/inicia sin UI; `--check` solamente verifica. La UI de error usa el dorado oficial #D4AF37, definido en assets/branding.json y originado en resources/css/app.css de Commerce.

## Build

`scripts/build-installer.ps1` compila launcher y pruebas, prepara QZ y genera dist/MVS-Print-Setup.exe y dist/release.json. Requiere el árbol QZ v2.2.6 ya construido, NSIS 3 y .NET Framework csc del sistema. El build completo de QZ usa scripts/build.ps1, JDK y Ant solo en desarrollo. No hay fallback que renombre un instalador QZ como producto MVS.

QZ se fija al commit 4be94301797d04684f4d70c6bbbff5d9acc36987. El empaquetado excluye dos ejemplos de firma para desarrolladores (sign-message.js y sign-message.vue.js), que no son necesarios en el cliente; uno incluye una clave privada pública de demostración. No modifica ejecutables ni JAR de QZ. El paquete incluye exclusivamente el certificado público MVS, nunca claves privadas ni credenciales.

La versión debe coincidir en VERSION, metadata del launcher y scripts. Antes de distribuir: ejecutar pruebas, auditar Authenticode, revisar contenido, generar hashes finales y descargar nuevamente desde HTTPS para compararlos.

## Firma y descarga

Branding y metadata no eliminan SmartScreen. Authenticode requiere firma de código confiable y timestamp; el certificado público de impresión QZ no sirve para firmar ejecutables. OV y EV no garantizan reputación inmediata. No desactivar seguridad. Referencias oficiales: https://learn.microsoft.com/en-us/windows/apps/package-and-deploy/smartscreen-reputation y https://learn.microsoft.com/en-us/windows/apps/package-and-deploy/code-signing-options .

Commerce reutiliza /mvs/print/descargar y MVS_PRINT_DOWNLOAD_URL, MVS_PRINT_VERSION, MVS_PRINT_SHA256. Servir el artefacto por HTTPS estático y verificar hash completo. La aprobación física final de Liberia sigue siendo obligatoria; un build correcto no acredita impresora, corte ni cajón.
