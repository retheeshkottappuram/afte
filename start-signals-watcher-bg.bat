@echo off
cd /d "%~dp0"
"C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64\php.exe" artisan crypto:watch-signals --sleep=25 >> storage\logs\watcher.log 2>&1
