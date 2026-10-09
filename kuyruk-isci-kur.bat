@echo off
rem =====================================================================
rem  kuyruk-isci-kur.bat - kuyruk iscisini Gorev Zamanlayici'ya kaydeder
rem ---------------------------------------------------------------------
rem  Bir kez cift tiklayin: kuyruk-isci.bat her oturum acilisinda gizli
rem  pencerede baslar, ayni anda tek ornek calisir. Komut satiri acmaniz
rem  ya da pencereyi acik tutmaniz gerekmez. Yonetici izni gerekmez.
rem
rem    kuyruk-isci-kur.bat           kaydeder ve hemen baslatir
rem    kuyruk-isci-kur.bat kaldir    gorevi siler
rem
rem  Isi kuyruk-isci-kur.ps1 yapar (tirnak ve & icin .bat guvenli degil).
rem  Depo satir sonlarini LF saklar: etiket / goto yok (bkz. kuyruk-isci.bat).
rem =====================================================================
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0kuyruk-isci-kur.ps1" %1
pause
