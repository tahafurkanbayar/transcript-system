# =====================================================================
#  kuyruk-isci-kur.ps1 - kuyruk-isci.bat'i Gorev Zamanlayici'ya kaydeder
# ---------------------------------------------------------------------
#  kuyruk-isci-kur.bat cagirir (cift tiklama). Gorev:
#    - oturum acilinca baslar (AtLogOn, yalniz bu kullanici)
#    - gizli pencerede calisir (powershell -WindowStyle Hidden; oturum
#      acilisinda bir an pencere gorunebilir)
#    - ayni anda tek ornek (MultipleInstances IgnoreNew)
#    - sure siniri yok (ExecutionTimeLimit 0), pilde de calisir
#  Yonetici izni gerekmez. Isletim sistemindeki tek kalici degisiklik bu
#  gorevdir; "kuyruk-isci-kur.bat kaldir" siler.
#
#  Satir sonlari LF (.gitattributes); PowerShell bunu sorunsuz okur.
# =====================================================================
param([string] $Islem = 'kur')

$ErrorActionPreference = 'Stop'
$gorev = 'Transkript kuyruk iscisi'
$kok   = $PSScriptRoot
$kim   = "$env:USERDOMAIN\$env:USERNAME"

if ($Islem -eq 'kaldir') {
    Unregister-ScheduledTask -TaskName $gorev -Confirm:$false
    Write-Host 'Gorev silindi. Kuyruk iscisi artik oturum acilinca baslamayacak.'
    return
}

if (-not (Test-Path (Join-Path $kok 'kuyruk-isci.bat'))) {
    throw "kuyruk-isci.bat bulunamadi: $kok"
}

$eylem = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command `"& '.\kuyruk-isci.bat'`"" `
    -WorkingDirectory $kok
$tetik = New-ScheduledTaskTrigger -AtLogOn -User $kim
$ayar  = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
$hesap = New-ScheduledTaskPrincipal -UserId $kim -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $gorev -Action $eylem -Trigger $tetik -Settings $ayar -Principal $hesap `
    -Description 'Transkript analiz kuyrugu: her dakika php cy schedule:run (kuyruk-isci.bat)' -Force | Out-Null
Start-ScheduledTask -TaskName $gorev

Write-Host 'Kaydedildi. Kuyruk iscisi simdi basladi; her oturum acilisinda gizli olarak kendiliginden baslar.'
Write-Host 'Gorev Zamanlayici > "Transkript kuyruk iscisi" ile durdurulabilir; silmek icin: kuyruk-isci-kur.bat kaldir'
