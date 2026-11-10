@echo off
REM Arranca todo lo que Lumora necesita en la PC con la placa:
REM   1. ComfyUI (127.0.0.1:8188), en su propia ventana minimizada.
REM   2. Espera a que ComfyUI responda (hasta 3 minutos).
REM   3. El gateway (127.0.0.1:8766), en su propia ventana minimizada.
REM Si alguno ya esta corriendo, no lo vuelve a abrir.
REM El tunel de Cloudflare es un servicio de Windows y arranca solo.
cd /d "%~dp0"
title Lumora - arranque

netstat -ano | findstr /r /c:":8188 .*LISTENING" >nul
if %errorlevel%==0 (
    echo ComfyUI ya estaba corriendo.
) else (
    echo Arrancando ComfyUI...
    start "ComfyUI (Lumora)" /min cmd /c "%~dp0iniciar-comfyui.bat"
)

echo Esperando a que ComfyUI responda...
set /a intentos=0
:esperar
curl -s -o nul --max-time 3 http://127.0.0.1:8188/system_stats
if %errorlevel%==0 goto comfy_listo
set /a intentos+=1
if %intentos% geq 60 (
    echo ComfyUI no respondio en 3 minutos. Arranco el gateway igual:
    echo va a mostrar "Placa apagada" hasta que ComfyUI levante.
    goto gateway
)
ping -n 4 127.0.0.1 >nul
goto esperar

:comfy_listo
echo ComfyUI listo.

:gateway
netstat -ano | findstr /r /c:":8766 .*LISTENING" >nul
if %errorlevel%==0 (
    echo El gateway ya estaba corriendo.
) else (
    echo Arrancando el gateway...
    start "Lumora GPU Gateway" /min cmd /c "%~dp0iniciar-gateway.bat"
)

echo Listo. Esta ventana se cierra en 10 segundos.
ping -n 11 127.0.0.1 >nul
