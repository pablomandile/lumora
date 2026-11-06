@echo off
REM Crea el entorno de Python del gateway de Lumora e instala las dependencias.
cd /d "%~dp0"

where python >nul 2>nul || (
    echo No se encontro Python. Instalalo desde https://www.python.org/downloads/ ^(marcar "Add to PATH"^).
    pause
    exit /b 1
)

if not exist .venv (
    python -m venv .venv || exit /b 1
)

call .venv\Scripts\python -m pip install --upgrade pip
call .venv\Scripts\python -m pip install -r requirements.txt || exit /b 1

if not exist .env (
    copy .env.example .env >nul
    echo.
    echo Se creo .env: abrilo y completa LUMORA_GPU_TOKEN antes de iniciar.
)

echo.
echo Listo. Para arrancar: iniciar-gateway.bat
pause
