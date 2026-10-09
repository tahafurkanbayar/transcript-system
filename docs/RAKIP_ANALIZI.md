# Rakip Analizi: YouTube Özetleme ve Transcript Araçları

9 Ekim 2026 · Kaynaklar sondadır. Fiyat ve limitler kaynaklardaki o günkü
bilgidir; değişebilir.

## 1. Kısa sonuç

Rakiplerin hepsi **zaman damgalı özet** ve **transcript** veriyor; bu artık
temel beklenti. Öne çıkanları ayıran şeyler:
- **Sohbet:** "videoya sor", cevapta zaman damgası.
- **Dışa aktarma:** Markdown, Notion, Obsidian.
- **Çalışma araçları:** zihin haritası, bilgi kartı, quiz.
- **Çok dil.**
- **Dağıtım:** tarayıcı eklentisi ve mobil uygulama.

Bizim farkımız (son üçü rakip sayfalarında geçmedi; ürünlerde elle denenmeli):
- **Türkçe öncelikli rapor:** İngilizce video da Türkçe rapor üretir.
- **Doğrulanmış zaman damgaları:** her damga gerçek bir segmente çekilir.
- **İddiaların ayrı tutulması:** kesin ifade, görüş ve tahmin olarak işaretlenir.
- **Ortak önbellek:** aynı video ikinci kez ödenmez.
- **Kendi sunucunda çalışma (KVKK).**
- **Açık API.**

Bu turda masrafsız ve hemen yapılabilecek beş özelliği ekledik (bkz. 4. bölüm).
Sohbet ve çalışma araçları maliyetli olduğu için öneri olarak bırakıldı
(5. bölüm).

## 2. Rakipler

| Araç | Öne çıkan | Örnek (kaynaktan) | Ücretsiz katman |
|---|---|---|---|
| **Eightify** | Tarayıcı içi, mobil öncelikli; anahtar fikirler, zaman damgalı özet, yorum özeti, 40+ dil | Fikir türü seçilir: "funny, actionable, controversial, insightful"; ücretsiz kullanıcıda 30 dk sınırı | Sınırlı |
| **NoteGPT** | Toplu özet (20 video), oynatma listesi, zihin haritası, bilgi kartı, quiz, sohbet | "No limits on video length"; altyazısız videoda 150 dk; 60+ dil; özet uzunluğu ve özel istem | Var, girişsiz |
| **Glasp** | YouTube'da oynatıcının yanında özet ve transcript; vurgulama ve not | "download as Markdown, HTML, CSV or JSON"; Notion / Obsidian / Roam aktarımı; sohbet "cite timestamps" | Özet ücretsiz; sohbet ayda 20 kredi |
| **Summarize.tech** | Sade: bölüm bölüm, zaman aralıklı özet | Uzunluk ayarı; çok dil | Günlük limit; 10 $/ay 200 video |
| **Recall** | Kişisel bilgi tabanı; içerikleri birbirine bağlar | YouTube 10 saate kadar; aralıklı tekrar (spaced repetition) kartları, soru üretimi | Var |
| **BibiGPT** | 30+ platform, zihin haritası, zaman damgalı soru-cevap, Notion / Obsidian / Readwise | 4 saat üstü video; ajan (Claude Code) entegrasyonu | Günlük kota |
| **YouTube "Ask" (Gemini)** | YouTube'un kendi düğmesi: "Summarize the video", bağlama göre önerilen sorular, takip sorusu | Cevaplar o ana atlayan zaman damgası içerir; "Ask YouTube" araması Premium'a özel | Premium / kademeli |
| **Clarify AI, YouMino, Transcript Dev** | Özet + transcript + sohbet + zihin haritası + dışa aktarma paketi | YouMino 150+ dil, "AI-generated articles"; Clarify yer imi ve 50+ dil | Değişken |
| **Türkçe arayüzlü (Lilys AI, Jotform, Speak AI, Maestra)** | URL yapıştır, özet al; çoğu çok amaçlı (PDF, ses) | Lilys: Türkçe dahil 12+ dil, web + mobil + eklenti | Var |

## 3. Özellik karşılaştırması

✓ var (kaynakta geçiyor) · ? kaynaklarda bilgi yok · *P* bizde planda
(`TEKNIK_RAPOR.md` 13. bölüm). "?" "yok" demek değildir; yalnız taradığımız
kaynaklarda geçmediğini gösterir.

| Özellik | Eightify | NoteGPT | Glasp | Summarize.tech | Recall | YouTube Ask | **Bizde** |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Zaman damgalı özet | ✓ | ✓ | ✓ | ✓ | ? | ✓ | **✓** |
| Tıklayınca videoda o ana gitme | ✓ | ? | ✓ | ✓ | ? | ✓ | **✓** gömülü oynatıcı |
| Tam transcript | ✓ | ✓ | ✓ | ? | ? | ? | **✓** + Türkçe katlamalı arama |
| Transcript oynatıcıyla yan yana / eş zamanlı | ? | ? | ✓ yan yana | ? | ? | ? | **✓ eş zamanlı (bu tur)** |
| Anahtar fikirler / önemli noktalar | ✓ | ✓ | ? | ? | ? | ✓ | **✓** Kritik / Önemli / Faydalı rozeti |
| İddiaların ayrı listelenmesi (kesin / görüş / tahmin) | ? | ? | ? | ? | ? | ? | **✓** |
| Dışa aktarma | ? | ✓ Notion / Obsidian | ✓ MD / HTML / CSV / JSON, Notion / Obsidian | ? | ? | ? | **✓ MD / TXT / SRT / VTT (bu tur)** |
| YouTube bölüm listesi (chapters) çıktısı | ? | ? | ? | ? | ? | ? | **✓ (bu tur)** |
| Transcript kopyalama | ? | ? | ✓ | ? | ? | ? | **✓ TXT + özet kopyala (bu tur)** |
| Videoya sor (sohbet) | ✓ soru-cevap | ✓ | ✓ zaman damgalı | ? | ✓ | ✓ zaman damgalı | *P* 4. aşama |
| Yorum özeti | ✓ | ? | ? | ? | ? | ? | — |
| Özel odak / uzunluk / istem | ✓ fikir türü | ✓ uzunluk + istem | ✓ istem + uzunluk | ✓ uzunluk | ? | ✓ önerilen sorular | *P* kişisel filtre (4b) |
| Zihin haritası | ? | ✓ | ? | ? | ? | ? | — |
| Bilgi kartı / quiz / aralıklı tekrar | ? | ✓ kart + quiz | ? | ? | ✓ aralıklı tekrar | ? | — |
| Toplu / oynatma listesi / kanal | ? | ✓ 20 video, liste, kanal | ✓ kanal takibi | ? | ? | ? | *P* Sonra |
| Ses / video dosyası yükleme | ? | ✓ | ? | ? | ? | ? | *P* 5b |
| Çok dilli çıktı | ✓ 40+ | ✓ 60+ | ✓ | ✓ | ? | ? | Türkçe rapor, her dilden kaynak |
| Uzun video | 30 dk (ücretsiz) | ✓ sınırsız | ? | ? | ✓ 10 saat | ? | **✓** parçala-birleştir (3 saat +) |
| Aynı video ikinci kez ücretsiz (ortak önbellek) | ? | ? | ? | ? | ? | ? | **✓** |
| Eklenti / mobil uygulama | ✓ ikisi de | ? | ✓ ikisi de | ? | ✓ | YouTube'un içinde | yer imi + `?url=` (bu tur), PWA |
| Kendi sunucunda, REST API | ? | ? | ? | ? | ? | ? | **✓** KVKK, Bearer API |

"İddiaların ayrı listelenmesi", "bölüm listesi çıktısı" ve "ortak önbellek"
taradığımız rakip sayfalarında geçmedi. Bunları farklılaştırıcı olarak
pazarlamadan önce rakip ürünlerde elle denenmeli.

## 4. Bu turda eklenenler

Hepsi AI çağrısı yapmaz; kayıtlı rapordan ve transcript'ten üretilir.

1. **Dışa aktarma** (Glasp ve NoteGPT'deki gibi). Rapor sayfasındaki
   "Dışa aktar" paneli şunları verir:
   - **Rapor (Markdown):** Notion ve Obsidian'a doğrudan girer; zaman
     damgaları `youtu.be?t=` bağlantısıdır.
   - **Transcript (TXT).**
   - **Altyazı (SRT ve VTT):** video düzenleyiciler ve oynatıcılar için.
   - AI metni Markdown'da kaçışlanır: `[tıkla](javascript:…)` bağlantıya,
     `#` başlığa dönüşmez.
2. **YouTube bölüm listesi:** "0:00 Giriş / 3:08 …" satırları tek tıkla
   kopyalanır; video açıklamasına yapıştırılınca YouTube'da bölümler
   (chapters) oluşur. YouTube kuralları uygulanır: ilk bölüm 0:00, en az 3
   bölüm, 10 saniyeden yakın bölüm yok. Rakiplerde görmediğimiz, içerik
   üreticisine doğrudan yarayan bir özellik.
3. **Transcript ile oynatıcı eş zamanlı** (Glasp'taki gibi): video oynarken
   o an konuşulan satır vurgulanır, liste kendiliğinden kayar ("Video
   oynarken o anki satırı göster" anahtarı). Oynatıcının kendi
   `postMessage` iletileriyle çalışır; dış betik yüklenmez.
4. **Kopyalama ve okuma süresi:** kısa özeti kopyala düğmesi;
   "~3 dk okuma" göstergesi (19 dakikalık videoya karşı).
5. **Hızlı başlatma** (rakiplerin eklenti kolaylığına hafif karşılık):
   - `panel/transkript?url=…` ile kutu dolu açılır, tek dokunuş kalır.
   - Masaüstü için yer imi: YouTube'dayken tıklayınca video bu sayfaya gelir.

## 5. Öneriler (öncelik sırasıyla)

| # | Öneri | Neden | Maliyet / risk | Planla ilişkisi |
|---|---|---|---|---|
| 1 | **Videoya sor (sohbet)**: cevap yalnız transcript'ten, tıklanabilir zaman damgalarıyla | Rakiplerin hepsinde var; YouTube'un kendisi de ekledi | Soru başına AI çağrısı; Search ile ilgili segmentler bulunup modele verilir | 4. aşama; tablolar ve rotalar hazır |
| 2 | **Odak seçimi**: "uygulanabilir", "teknik", "eleştirel" vb. ve özet uzunluğu | Eightify, Glasp ve NoteGPT'de var; kişisel filtrenin ilk adımı | Ucuz (reduce'u yeniden çalıştırmak yeter) | 4b kişisel filtre |
| 3 | **Zihin haritası**: konular → noktalar ağacı | NoteGPT ve BibiGPT'nin vitrin özelliği | AI gerekmez, rapordan SVG üretilir; yeni kütüphane eklenmez | Yeni |
| 4 | **Yorum özeti** | Yalnız Eightify'da; izleyici tepkisi | YouTube Data API (anahtarın kısıtı düzeltilmeli) + 1 AI çağrısı | Yeni |
| 5 | **Salt okunur paylaşım bağlantısı** | Raporu ekip içinde paylaşmak | Yetki ve gizlilik tasarımı gerekir | Yeni |
| 6 | **Bilgi kartı / quiz** | NoteGPT ve Recall; eğitim kullanımı | AI çağrısı; ayrı tablo | Yeni |
| 7 | **Oynatma listesi / kanal analizi** | NoteGPT'de toplu 20 video | Kuyruk hazır; kota ve maliyet sınırı şart | Planda "Sonra" |
| 8 | **Rapor dili seçimi** (TR / EN …) | Rakipler 40–60 dil sunuyor | İstem değişkeni; önbellek anahtarına dil girer | Yeni |

Önerim: 9. adım (hata yönetimi) ve 10. adım (test) MVP'yi kapatsın; ardından
1. madde (sohbet) gelsin. En çok fark yaratan ve altyapısı en hazır olan
özellik o.

## Kaynaklar

- [BibiGPT – Top 10 YouTube Video Summarizer (2026)](https://bibigpt.co/blog/posts/top-10-youtube-ai-video-summary-tools-2025)
- [BibiGPT – Best AI Tools to Summarize YouTube Videos (2026)](https://growth.bibigpt.co/blog/posts/youtube-video-summarizer-tools-comprehensive-guide-2026)
- [NoteGPT – YouTube Video Summarizer](https://notegpt.io/youtube-video-summarizer)
- [Glasp – YouTube Summary](https://glasp.co/youtube-summary)
- [Eightify incelemesi – Educators Technology](https://www.educatorstechnology.com/2024/12/eightify-youtube-video-summarizer.html)
- [Eightify incelemesi – Briefy](https://blog.briefy.ai/eightify-detailed-product-review-best-alternative-2024/)
- [Summarize.tech incelemesi – Tools for Humans](https://www.toolsforhumans.ai/ai-tools/summarize-tech-7eb77)
- [Recall – Subscribed.FYI](https://subscribed.fyi/blog/recall-save-time-summarize-youtube-articles-podcasts-pdfs-recipes-and-more/)
- [Recall – Hakkında](https://www.recall.it/about)
- [YouTube Gemini "Ask" – Geekflare](https://geekflare.com/news/youtube-adds-gemini-ai-powered-ask-button/)
- [Ask YouTube, Google I/O 2026 – Dataconomy](https://dataconomy.com/2026/05/20/youtube-ask-youtube-gemini-omni-ai-google-io/)
- [Clarify AI – Firefox eklentisi](https://addons.mozilla.org/addon/clarify-ai/)
- [YouMino – Product Hunt](https://www.producthunt.com/posts/youmino)
- [Lilys AI – YouTube özet araçları (Türkçe)](https://lilys.ai/notes/tr/youtube-summary-ai/)
- [Webrazzi – Video ve makale özetleyen 5 araç](https://webrazzi.com/2024/12/09/video-ve-makale-ozetleyen-yapay-zeka-destekli-5-arac/)
- [Jotform – YouTube Video Özetleyici](https://www.jotform.com/tr/ai/summarizer/youtube-video/)
- [Speak AI – YouTube Özetleyici](https://speakai.co/tr/yapay-zeka-youtube-ozetleyici/)
