@echo off
cd /d "%~dp0"
set MODE=%1
if "%MODE%"=="" set MODE=live
"C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64\php.exe" artisan trade:daemon --mode=%MODE% >> storage\logs\trading_daemon.log 2>&1
