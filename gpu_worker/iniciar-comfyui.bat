@echo off
REM Arranca ComfyUI escuchando solo en la PC (127.0.0.1:8188), sin abrir navegador.
REM Sirve para las dos instalaciones:
REM   - Comfy Desktop: usa la instancia "Lumora" sin abrir la app de escritorio.
REM   - ComfyUI portable: ajusta COMFY_DIR si lo descomprimiste en otro lado.
title ComfyUI (Lumora)

set DESKTOP_DIR=%LOCALAPPDATA%\Comfy-Desktop\ComfyUI-Installs\Lumora
set DESKTOP_SHARED=%LOCALAPPDATA%\Comfy-Desktop\ComfyUI-Shared
set COMFY_DIR=C:\ComfyUI_windows_portable

if exist "%DESKTOP_DIR%\ComfyUI\.venv\Scripts\python.exe" goto desktop
if exist "%COMFY_DIR%\python_embeded\python.exe" goto portable

echo No encuentro ComfyUI (ni Comfy Desktop ni la portable en %COMFY_DIR%).
echo Edita las rutas al principio de este archivo.
pause
exit /b 1

:desktop
REM Las rutas de modelos las genera Comfy Desktop; se toma el .yaml de la instancia.
set MODEL_PATHS=
for %%f in ("%APPDATA%\Comfy Desktop\instance-model-paths\*.yaml") do set MODEL_PATHS=%%f
if "%MODEL_PATHS%"=="" (
    echo No encuentro el archivo de rutas de modelos de Comfy Desktop.
    pause
    exit /b 1
)
cd /d "%DESKTOP_DIR%"
ComfyUI\.venv\Scripts\python.exe -s ComfyUI\main.py --listen 127.0.0.1 --port 8188 --disable-auto-launch ^
    --extra-model-paths-config "%MODEL_PATHS%" ^
    --input-directory "%DESKTOP_SHARED%\input" ^
    --output-directory "%DESKTOP_SHARED%\output"
exit /b %errorlevel%

:portable
cd /d "%COMFY_DIR%"
.\python_embeded\python.exe -s ComfyUI\main.py --windows-standalone-build --listen 127.0.0.1 --port 8188 --disable-auto-launch
