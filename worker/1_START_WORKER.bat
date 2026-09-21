@echo off
REM WhatsApp Bot Worker Startup Script
REM Run this to start the WhatsApp Worker

echo.
echo ========================================
echo  WhatsApp Bot Control Panel
echo  Worker Startup
echo ========================================
echo.

REM Check Python installation
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH
    echo Please install Python 3.12+ from https://www.python.org
    pause
    exit /b 1
)

echo Python found:
python --version
echo.

REM Navigate to worker directory
cd /d "%~dp0"

REM Check if requirements are installed
echo Checking dependencies...
python -m pip show playwright >nul 2>&1
if errorlevel 1 (
    echo Installing Playwright...
    python -m pip install playwright
    echo Installing Playwright browsers...
    playwright install
)

python -m pip show requests >nul 2>&1
if errorlevel 1 (
    echo Installing requests...
    python -m pip install requests
)

REM Create logs directory
if not exist "logs" mkdir logs

echo.
echo Starting WhatsApp Worker...
echo Configuration file: worker_config.json
echo Logs: logs\worker.log
echo.
echo Press Ctrl+C to stop the worker
echo.

REM Start worker
python whatsapp_worker.py worker_config.json

pause
