@echo off
rem =====================================================================
rem  kuyruk-isci.bat - Windows / XAMPP icin kuyruk iscisi
rem ---------------------------------------------------------------------
rem  Cron olmayan makinede analizler "Sirada bekliyor"da kalir. Bu dosya
rem  her dakika "php cy schedule:run" calistirir: Transkript analiz
rem  isleri, e-posta kuyrugu ve bakim gorevleri.
rem
rem    kuyruk-isci.bat          surekli calisir (pencere kapaninca durur)
rem    kuyruk-isci.bat --bir    tek tur calistirip cikar
rem
rem  PHP: once CY_PHP ortam degiskeni, sonra C:\xampp\php\php.exe,
rem  yoksa PATH uzerindeki php. Gorev Zamanlayici ile oturum acilinca
rem  baslatmak icin: modules/Transkript/README.md, "Kuyruk iscisi".
rem
rem  Depo satir sonlarini LF saklar (.gitattributes); cmd LF dosyada
rem  etiket / goto ile sasirabildigi icin dongu "for /l" ile kurulur.
rem  Bekleme icin "timeout" yerine ping: Gorev Zamanlayici konsolsuz
rem  calistirinca timeout hemen cikar ve dongu bosa doner.
rem =====================================================================
setlocal
cd /d "%~dp0"

set "PHP=php"
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if defined CY_PHP set "PHP=%CY_PHP%"

if /i "%~1"=="--bir" (
    "%PHP%" cy schedule:run
    exit /b
)

echo Kuyruk iscisi calisiyor. Durdurmak icin pencereyi kapatin.
for /l %%i in (0,0,1) do (
    "%PHP%" cy schedule:run
    ping -n 61 127.0.0.1 >nul
)
