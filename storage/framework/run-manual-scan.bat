@echo off
cd /d "C:\laragon\www\autotrade"
"C:\laragon\bin\php\php-8.4.5-nts-Win32-vs17-x64\php.exe" artisan crypto:check-signals --all --dry-run >> "C:\laragon\www\autotrade\storage\logs\manual_scan.log" 2>&1
