@echo off
REM PayZephyr Cleanup Script for Windows

echo ====================================
echo PayZephyr Package Cleanup Script
echo ====================================
echo.

echo Removing orphaned webhook files...
if exist "src\Webhook\SignatureVerifier.php" (
    del /F /Q "src\Webhook\SignatureVerifier.php"
    echo [OK] Deleted: src\Webhook\SignatureVerifier.php
) else (
    echo [SKIP] Not found: src\Webhook\SignatureVerifier.php
)

if exist "src\Webhook\WebhookController.php" (
    del /F /Q "src\Webhook\WebhookController.php"
    echo [OK] Deleted: src\Webhook\WebhookController.php
) else (
    echo [SKIP] Not found: src\Webhook\WebhookController.php
)

if exist "src\Webhook" (
    rmdir /S /Q "src\Webhook"
    echo [OK] Deleted: src\Webhook directory
) else (
    echo [SKIP] Not found: src\Webhook directory
)

echo.
echo Removing duplicate route file...
if exist "src\Routes\webhook.php" (
    del /F /Q "src\Routes\webhook.php"
    echo [OK] Deleted: src\Routes\webhook.php
) else (
    echo [SKIP] Not found: src\Routes\webhook.php
)

if exist "src\Routes" (
    rmdir /S /Q "src\Routes"
    echo [OK] Deleted: src\Routes directory
) else (
    echo [SKIP] Not found: src\Routes directory
)

echo.
echo Removing incomplete helper...
if exist "src\Helpers\CurrencyConverter.php" (
    del /F /Q "src\Helpers\CurrencyConverter.php"
    echo [OK] Deleted: src\Helpers\CurrencyConverter.php
) else (
    echo [SKIP] Not found: src\Helpers\CurrencyConverter.php
)

if exist "src\Helpers" (
    rmdir /S /Q "src\Helpers"
    echo [OK] Deleted: src\Helpers directory
) else (
    echo [SKIP] Not found: src\Helpers directory
)

echo.
echo ====================================
echo Cleanup complete!
echo ====================================
echo.
echo Summary of deleted files:
echo   - src\Webhook\ (entire directory)
echo   - src\Routes\ (entire directory)  
echo   - src\Helpers\CurrencyConverter.php
echo.
echo Next steps:
echo   1. Run: composer dump-autoload
echo   2. Run: composer test
echo   3. Update documentation (see AUDIT_REPORT.md)
echo.
pause
