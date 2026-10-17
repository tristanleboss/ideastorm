@echo off
rem ---------------------------------------------------------------------------
rem Installs or updates IdeaStorm in C:\wamp64\www\idees from the GitHub branch.
rem
rem Run it from a Command Prompt (not by double-click, so you can read any error):
rem     scripts\deploy-wamp.bat
rem or, the very first time, before the folder exists:
rem     curl -L -o deploy-wamp.bat https://raw.githubusercontent.com/tristanleboss/ideastorm/claude/beautiful-newton-rc2vmh/scripts/deploy-wamp.bat
rem
rem Needs git, PHP and Composer on the PATH. WAMP's PHP is not on the PATH by default:
rem add C:\wamp64\bin\php\php8.3.28 to it (Windows > Environment Variables), or edit PHP_DIR.
rem ---------------------------------------------------------------------------
setlocal
set TARGET=C:\wamp64\www\idees
set BRANCH=claude/beautiful-newton-rc2vmh
set REPO=https://github.com/tristanleboss/ideastorm
set PHP_DIR=C:\wamp64\bin\php\php8.3.28

if exist "%PHP_DIR%\php.exe" set PATH=%PHP_DIR%;%PATH%

if not exist "%TARGET%\.git" (
    echo Cloning into %TARGET% ...
    git clone --branch %BRANCH% %REPO% "%TARGET%" || goto :fail
    set FIRST_INSTALL=1
) else (
    echo Updating %TARGET% ...
    git -C "%TARGET%" fetch origin %BRANCH% || goto :fail
    git -C "%TARGET%" checkout %BRANCH% || goto :fail
    git -C "%TARGET%" pull --ff-only origin %BRANCH% || goto :fail
)

cd /d "%TARGET%"
call composer install --no-interaction || goto :fail
php bin/console doctrine:migrations:migrate --no-interaction || goto :fail

rem Demo data is loaded once, on first install only: loading fixtures purges the database.
if defined FIRST_INSTALL (
    php bin/console doctrine:fixtures:load --no-interaction || goto :fail
)
php bin/console cache:clear || goto :fail

echo.
echo Done. If you have not yet added the Apache alias, see "Running it on WAMP" in README.md:
echo     alias  idees  ->  C:/wamp64/www/idees/public/
echo then open http://localhost/idees
exit /b 0

:fail
echo.
echo *** Deployment stopped: see the error above. ***
exit /b 1
