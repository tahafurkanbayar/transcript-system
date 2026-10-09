# Transkript modülü: değişiklik günlüğü

Starter'ın sürüm geçmişi kökteki `CHANGELOG.md`'dedir; bu dosya yalnız
`modules/Transkript/` içindir. Biçim [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/).

## 0.2.0 (2026-10-09): test raporu düzeltmeleri

Uçtan uca test raporundaki (`transkript-test-raporu.md`) bulgular; her biri
ayrı dalda (`duzeltme-1` … `duzeltme-7`).

### Eklenenler
- **Kuyruk işçisi uyarısı:** işçi 2 dakikadır görülmediyse ve iş bekliyorsa
  liste, rapor ve kontrol paneli uyarır; ilerleme JSON'unda `isci_calisiyor`,
  `kuyruk_takili`, `sirada`. Proje kökünde `kuyruk-isci.bat` (Windows), README'de
  Görev Zamanlayıcı. Geliştirme ortamında yöneticiye "Şimdi işle".
- **Video ön kontrolü:** kayıttan önce oEmbed; olmayan / gizli video 422,
  kayıt açılmaz. Oynatma listesi ve kanal bağlantısına ayrı mesaj. En uzun
  video sınırı (180 dk).
- **Kayıt yönetimi:** sil, iptal, yeniden dene; panelin onay penceresi.
- **Kotalar:** kullanıcı başına günlük analiz, genel günlük token / maliyet
  tavanı (429).
- **Ayarlar ekranı** ve kontrol paneli kartları.
- Markdown künyesi (tarih, model, istem, transcript dili); eski istem notu.

### Değişenler
- Sayaçlar raporu sayar: "raporu hazır video", "bugün tamamlanan"; altında
  "2 sürüyor · 1 hata".
- Liste: Tür sütunu kalktı, durum tek sütun, işlemler sütunu; video kimliğinin
  parçasıyla arama.
- Konu aralıkları kesintisiz (ilk konu 00:00, çakışma yok).
- İstem v3 (aşağıda). "Teknik bilgiler" → "Kavramlar ve teknik bilgiler".

### Düzeltilenler
- Listede Türkçe karakterli arama 500 veriyordu ("Illegal mix of collations").
- Filtre açıkken önem rozeti değişmiyordu; tek harf aramada "0 sonuç";
  transcript yüklenemezse yeniden denenmiyordu; bekleyen raporda zaman damgası
  ipucu görünüyordu.
- İptal edilen analize geç gelen iş rapor yazıp önceki raporu güncellikten
  düşürebilirdi.

## İstem sürümleri (`prompts/*.vN.txt`)

Analiz, kullandığı istemin sürümünü `transkript_analizler.prompt_surumu`
sütununda saklar; eski sürüm dosyaları silinmez (eski raporun nasıl
üretildiği okunabilsin). Güncel sürüm `config.php` → `ai.istem_surumu`.
Rapor sayfası eski sürümle üretilmiş rapora not düşer.

### v3 (2026-10-09)

Neden: v2, 18 saniyelik tek cümlelik videoya ("Me at the zoo") 5 cümlelik
özet, 3 genel geçer öneri ("belgesellerden öğrenin") ve 1 iddia yazdı; bir
eğitim konuşmasında kişi adlarını "Teknik bilgiler"e koydu; konu aralıkları
çakıştı (test raporu, bulgu 7, 8, 10).

- **Uzunluk videoya göre:** PHP, video süresi ve transcript'in kelime
  sayısından bir "Uzunluk:" kuralı hesaplar (`Pipeline::raporUzunlugu`,
  `parcaUzunlugu`) ve kullanıcı mesajına ekler. 2 dakikadan kısa ya da 300
  kelimeden az: özet 1–2 cümle, detaylı özet boş ya da tek paragraf, en çok
  2 konu / 3 nokta. 2–15 dakika: özet 3–4 cümle. Daha uzunu v2 gibi.
- **Sayılar üst sınır, hedef değil:** "en önemli 5–15 nokta" gibi alt
  sınırlar kaldırıldı; içerik yoksa liste boş dizi. Öneri, iddia, fikir ve
  teknik bilgi için "genel geçer tavsiye uydurma, yoksa boş dizi" kuralı.
- **Teknik bilgi:** araç, yöntem, kavram ve terim, sayı, kaynak; kişi adı
  tek başına yazılmaz. Sayfada başlık "Kavramlar ve teknik bilgiler".
- **Konular:** zaman sırasıyla, üst üste binmeden, ilk konu 00:00:00.
  PHP de bunu zorlar (`ResultValidator::konulariDuzenle`).
- Gerçek deneme (ücretsiz Gemini 2.5 Flash): "Me at the zoo" v2 → v3 özet
  5 → 2 cümle, öneri 3 → 0, iddia 1 → 0, zaman çizelgesi 3 → 1. TED (19 dk):
  konu 2 (çakışan) → 3 (kesintisiz, 19:19'da biter).

### v2 (2026-10-09)

- Başlık ve kanal adı yalnız bağlamdır: transcript'te geçmeyen tarih, unvan,
  sıralama ("ilk", "en çok") yazılmaz. v1, "Me at the zoo"ya "YouTube'a
  yüklenen ilk video" yazmıştı.

### v1 (2026-10-09)

- İlk sürüm: parça analizi (map) ve birleştirme (reduce), JSON şeması,
  zaman damgası yalnız transcript satırlarından.
