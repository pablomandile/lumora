@echo off
REM Arranca ComfyUI portable escuchando solo en la PC (127.0.0.1:8188).
REM Ajusta COMFY_DIR si lo descomprimiste en otro lado.
set COMFY_DIR=C:\ComfyUI_windows_portable
title ComfyUI (Lumora)

if not exist "%COMFY_DIR%\python_embeded\python.exe" (
    echo No encuentro ComfyUI en %COMFY_DIR%. Edita COMFY_DIR en este archivo.
    pause
    exit /b 1
)

cd /d "%COMFY_DIR%"
REM --disable-auto-launch: no abre el navegador al iniciar con Windows.
.\python_embeded\python.exe -s ComfyUI\main.py --windows-standalone-build --listen 127.0.0.1 --port 8188 --disable-auto-launch
