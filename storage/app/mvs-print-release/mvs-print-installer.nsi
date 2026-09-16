; MVS Print wrapper. QZ Tray 2.2.6 remains a separately licensed internal engine.
Unicode True
!include "MUI2.nsh"
!include "x64.nsh"
!include "LogicLib.nsh"
!include "FileFunc.nsh"
!addplugindir /x86-unicode "..\_build\qz-tray\ant\windows\nsis\Plugins\Release_Unicode"
!addincludedir "..\_build\qz-tray\ant\windows\nsis\Include"
!include "StdUtils.nsh"
!ifndef MVS_VERSION
!define MVS_VERSION "1.0.2"
!endif
!define QZ_VERSION "2.2.6"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\MVS Print"
Name "MVS Print ${MVS_VERSION}"
OutFile "..\dist\MVS-Print-Setup.exe"
InstallDir "$PROGRAMFILES64\MVS Print"
RequestExecutionLevel admin
SetCompressor /SOLID lzma
VIProductVersion "${MVS_VERSION}.0"
VIAddVersionKey "FileDescription" "MVS Print Installer"
VIAddVersionKey "ProductName" "MVS Print"
VIAddVersionKey "ProductVersion" "${MVS_VERSION}"
VIAddVersionKey "FileVersion" "${MVS_VERSION}.0"
VIAddVersionKey "CompanyName" "MVS Commerce"
VIAddVersionKey "LegalCopyright" "Copyright (c) 2026 MVS Commerce"
!define MUI_ICON "..\_build\mvs-launcher\mvs-print.ico"
!define MUI_UNICON "..\_build\mvs-launcher\mvs-print.ico"
!define MUI_WELCOMEFINISHPAGE_BITMAP "..\_build\mvs-launcher\welcome.bmp"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE "Instalar MVS Print"
!define MUI_WELCOMEPAGE_TEXT "MVS Print conecta MVS Commerce con sus impresoras.$\r$\nIncluye QZ Tray 2.2.6 y el certificado PUBLICO de MVS Commerce.$\r$\n$\r$\nFinalice las impresiones pendientes antes de instalar."
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_FUNCTION LaunchMvs
!define MUI_FINISHPAGE_RUN_TEXT "Abrir MVS Print"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "license.txt"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Spanish"
Var QzPath
Var QzVersion

Function .onInit
    ${IfNot} ${RunningX64}
        MessageBox MB_ICONSTOP "MVS Print requiere Windows de 64 bits."
        Abort
    ${EndIf}
    SetRegView 64
    SetShellVarContext all
    ${If} $INSTDIR == ""
        StrCpy $INSTDIR "$PROGRAMFILES64\MVS Print"
    ${EndIf}
    ReadRegStr $QzPath HKLM "Software\QZ Tray" ""
    ReadRegStr $QzVersion HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\QZ Tray" "DisplayVersion"
    ${If} $QzPath == ""
        SetRegView 32
        ReadRegStr $QzPath HKLM "Software\QZ Tray" ""
        ReadRegStr $QzVersion HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\QZ Tray" "DisplayVersion"
        SetRegView 64
    ${EndIf}
    ${If} $QzPath == ""
        IfFileExists "$PROGRAMFILES64\QZ Tray\qz-tray.exe" 0 +2
        StrCpy $QzPath "$PROGRAMFILES64\QZ Tray"
    ${EndIf}
    ${If} $QzPath != ""
        IfFileExists "$QzPath\qz-tray.exe" +3 0
        MessageBox MB_ICONSTOP "Registro QZ existente sin ejecutable. Repare QZ antes de instalar MVS Print."
        Abort
        ${If} $QzVersion != "${QZ_VERSION}"
            MessageBox MB_YESNO|MB_ICONQUESTION "QZ Tray $QzVersion ya esta instalado. Se conservara sin reemplazarlo. Continuar con esta version (validacion fisica requerida)?" /SD IDNO IDYES +2
            Abort
        ${EndIf}
    ${EndIf}
FunctionEnd

Function LaunchMvs
    ${StdUtils.ExecShellAsUser} $0 "$INSTDIR\MVS Print.exe" "open" ""
FunctionEnd

Section "MVS Print" Main
    ${If} $QzPath == ""
        InitPluginsDir
        SetOutPath "$PLUGINSDIR"
        File "..\_build\qz-tray\out\qz-tray-2.2.6-mvs-x86_64.exe"
        StrCpy $QzPath "$PROGRAMFILES64\QZ Tray"
        ExecWait '"$PLUGINSDIR\qz-tray-2.2.6-mvs-x86_64.exe" /S /D=$QzPath' $0
        ${If} $0 != 0
            Abort "El motor QZ no pudo instalarse. Codigo $0."
        ${EndIf}
        IfFileExists "$QzPath\qz-tray.exe" +2 0
        Abort "No se encontro el motor de impresion."
    ${EndIf}
    SetOutPath "$INSTDIR"
    CreateDirectory "$INSTDIR"
    Delete "$INSTDIR\version.txt"
    Delete "$SMPROGRAMS\MVS Print\Uninstall.lnk"
    File "..\_build\mvs-launcher\MVS Print.exe"
    File "mvs-public-certificate.crt"
    ExecWait '"$INSTDIR\MVS Print.exe" --configure-trust' $0
    ${If} $0 != 0
        Abort "No se pudo configurar la confianza MVS. No se registra una instalacion completa."
    ${EndIf}
    ; The public certificate is allowed through QZ's official command, never a private key.
    nsExec::ExecToLog '"$QzPath\qz-tray-console.exe" --allow "$INSTDIR\mvs-public-certificate.crt"'
    Pop $0
    ${If} $0 != 0
        Abort "No se pudo autorizar el certificado publico en QZ."
    ${EndIf}
    ; Official QZ lifecycle reloads newly configured public trust during installation.
    ${StdUtils.ExecShellAsUser} $0 "$QzPath\qz-tray.exe" "open" "--steal"
    Sleep 1500
    ExecWait '"$INSTDIR\MVS Print.exe" --verify' $0
    ${If} $0 != 0
        Abort "El motor MVS Print no responde. La instalacion no se considera completa."
    ${EndIf}
    SetOutPath "$INSTDIR\licenses"
    File "..\licenses\ATTRIBUTION.txt"
    File "..\licenses\QZ-TRAY-LICENSE.txt"
    File "license.txt"
    SetOutPath "$INSTDIR"
    FileOpen $0 "$INSTDIR\version" w
    FileWrite $0 "${MVS_VERSION}$\r$\n"
    FileClose $0
    CreateDirectory "$SMPROGRAMS\MVS Print"
    CreateShortCut "$SMPROGRAMS\MVS Print\MVS Print.lnk" "$INSTDIR\MVS Print.exe"
    CreateShortCut "$DESKTOP\MVS Print.lnk" "$INSTDIR\MVS Print.exe"
    ; Reuse QZ's shared startup entry; never create a second engine startup.
    IfFileExists "$SMSTARTUP\QZ Tray.lnk" +2 0
    CreateShortCut "$SMSTARTUP\QZ Tray.lnk" "$QzPath\qz-tray.exe" "--honorautostart"
    WriteUninstaller "$INSTDIR\uninstall.exe"
    CreateShortCut "$SMPROGRAMS\MVS Print\Desinstalar.lnk" "$INSTDIR\uninstall.exe"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayName" "MVS Print"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayVersion" "${MVS_VERSION}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "Publisher" "MVS Commerce"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "InstallLocation" "$INSTDIR"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "QzPath" "$QzPath"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayIcon" "$INSTDIR\MVS Print.exe"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "URLInfoAbout" "https://app.mvscommerce.com"
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoModify" 1
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 1
SectionEnd

Function un.onInit
    SetRegView 64
    SetShellVarContext all
    ReadRegStr $QzPath HKLM "${UNINSTALL_KEY}" "QzPath"
FunctionEnd
Section "Uninstall"
    ; Do not stop, remove or downgrade the shared QZ installation.
    ExecWait '"$INSTDIR\MVS Print.exe" --remove-trust' $0
    ${If} $0 != 0
        Abort "No se pudo retirar la confianza MVS. Revise la configuracion antes de desinstalar."
    ${EndIf}
    ; Stop MVS Print if running before deleting files
    nsExec::ExecToLog 'taskkill /IM "MVS Print.exe" /F'
    Sleep 500
    Delete "$INSTDIR\MVS Print.exe"
    Delete "$INSTDIR\mvs-public-certificate.crt"
    Delete "$INSTDIR\licenses\ATTRIBUTION.txt"
    Delete "$INSTDIR\licenses\QZ-TRAY-LICENSE.txt"
    Delete "$INSTDIR\licenses\license.txt"
    RMDir "$INSTDIR\licenses"
    Delete "$INSTDIR\version.txt"
    Delete "$INSTDIR\version"
    Delete "$INSTDIR\uninstall.exe"
    RMDir "$INSTDIR"
    Delete "$SMPROGRAMS\MVS Print\MVS Print.lnk"
    Delete "$SMPROGRAMS\MVS Print\Desinstalar.lnk"
    Delete "$SMPROGRAMS\MVS Print\Uninstall.lnk"
    RMDir "$SMPROGRAMS\MVS Print"
    Delete "$DESKTOP\MVS Print.lnk"
    DeleteRegKey HKLM "${UNINSTALL_KEY}"
SectionEnd
