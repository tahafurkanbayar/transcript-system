/* =====================================================================
 *  Transkript modülü – panel ekranlarının betiği
 * ---------------------------------------------------------------------
 *  Sunum: panel/transkript/varlik/transkript.js (bkz. src/Varliklar.php),
 *  <script defer> ile app.js'ten SONRA çalışır (CY, jQuery hazır).
 *
 *  GÜVENLİK: sunucudan gelen her metin (AI çıktısı, transcript, hata
 *  mesajı) textContent / jQuery .text() ile basılır; innerHTML yok
 *  (CLAUDE.md, kural 7). CSRF başlığını app.js'in $.ajaxSetup'ı ekler.
 *
 *  BÖLÜMLER
 *    1) Yeni analiz formu (önbellek uyarısı, yeniden analiz)
 *    2) Listede süren analizlerin canlı ilerlemesi (3 sn)
 *    3) Rapor sayfasında aşama ilerlemesi (3 sn), bitince yenileme
 *    4) Gömülü oynatıcı: zaman damgası → seekTo (postMessage; dış betik yok)
 *    5) Önem filtresi, 6) Yeniden analiz düğmesi, kutu dolu geldiyse odak
 *    7) Transcript: açılınca yükleme; arama debounce + seq yarış koruması
 *       ve Türkçe katlamalı vurgulama (CilginYazilim/search-autocomplete,
 *       assets/js/search.js'ten uyarlandı); oynatıcıyla eş zamanlı satır
 *    8) Oynatıcının anlık zamanı (infoDelivery) → o anki transcript satırı
 *    9) "Kuyruk işçisi çalışmıyor" uyarısı ve "Şimdi işle" (yönetici)
 *   10) Kayıt: iptal ve silme. Onaylar panelin pencere biçimiyle
 *       (views/_parcalar/onay-penceresi.php); tarayıcının confirm()
 *       kutusu kullanılmaz.
 * ===================================================================== */
(function ($) {
    'use strict';

    if (!$ || !window.CY) {
        return;
    }

    var ARALIK_MS = 3000;
    var YOUTUBE   = 'https://www.youtube-nocookie.com';

    /* Metinli öğe üretir — HTML yorumlanmaz. */
    function el(etiket, sinif, metin) {
        var dugum = document.createElement(etiket);

        if (sinif) { dugum.className = sinif; }
        if (metin !== undefined && metin !== null) { dugum.textContent = String(metin); }

        return dugum;
    }

    /* Onay penceresi (#tk_onay). ayar: { metin, baslik, dugme, tehlike }.
     * "Devam" yalnız onay düğmesine basılıp pencere kapanınca çağrılır;
     * pencere yoksa işlem yapılmaz (onaysız silme olmasın). */
    function onayla(ayar, devam) {
        var kutu = document.getElementById('tk_onay');

        if (!kutu || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }

        var evet = kutu.querySelector('.js-tk-onay-evet');
        var onaylandi = false;

        kutu.querySelector('.js-tk-onay-baslik').textContent = ayar.baslik || 'Emin misiniz?';
        kutu.querySelector('.js-tk-onay-metin').textContent = ayar.metin || '';
        evet.textContent = ayar.dugme || 'Devam et';
        evet.className = 'btn cy-btn cy-btn--sm js-tk-onay-evet ' + (ayar.tehlike ? 'cy-btn--danger' : 'cy-btn--primary');

        var pencere = window.bootstrap.Modal.getOrCreateInstance(kutu);

        evet.onclick = function () {
            onaylandi = true;
            pencere.hide();
        };

        $(kutu).off('hidden.bs.modal.tk').one('hidden.bs.modal.tk', function () {
            evet.onclick = null;

            if (onaylandi) { devam(); }
        });

        pencere.show();
    }

    /* Düğmenin data-onay-* öznitelikleri → onay; data-onay yoksa doğrudan devam. */
    function dugmeOnayi(dugme, devam) {
        var metin = dugme.getAttribute('data-onay');

        if (!metin) {
            devam();
            return;
        }

        onayla({
            metin: metin,
            baslik: dugme.getAttribute('data-onay-baslik'),
            dugme: dugme.getAttribute('data-onay-dugme'),
            tehlike: dugme.getAttribute('data-onay-tehlike') === '1'
        }, devam);
    }

    /* İlerleme yanıtındaki kuyruk_takili → "Kuyruk işçisi çalışmıyor" uyarısı;
     * "Sayfayı kapatabilirsiniz" notu yalnız işçi varken (tarayıcıdan
     * işlenirken sayfa kapanırsa analiz bekler: iki çelişkili cümle olmasın). */
    function kuyrukUyarisi(d) {
        if (d && typeof d.kuyruk_takili === 'boolean') {
            $('.js-tk-kuyruk-uyari').prop('hidden', !d.kuyruk_takili);
        }

        if (d && typeof d.tarayici_isle === 'boolean') {
            $('.js-tk-not-isci').prop('hidden', d.tarayici_isle);
            $('.js-tk-not-tarayici').prop('hidden', !d.tarayici_isle);
        }

        tarayicidanIsle(d);
    }

    /* Komut satırısız kuyruk: işçi yokken (tarayici_isle) sunucu, kilitle TEK
     * bir işi çalıştırır. TİK DÖNGÜSÜ (cowork 3. tur): yanıt "bekleyen" iş
     * söyledikçe bir sonraki tik hemen (işlediyse 1,5 sn, kilit doluysa
     * 10 sn) atılır; sayfanın kendi analizi bitse ya da sekme arka plana
     * geçse de kuyruk boşalana kadar sürer. Eskiden yalnız ilerleme
     * yoklaması tetikliyordu: analiz bitince ya da sekme gizlenince
     * duruyor, öteki analizlerin işleri sırada kalıyordu (11.10.2026,
     * 04:20–04:30). Bütün Transkript sayfaları (ayarlar dahil) alt
     * menüdeki data-tk-tik ile katılır. global:false → hata bildirimi yok. */
    var $tikKaynagi = $('[data-tk-tik]');
    var tikAdresi = $('.js-tk-kuyruk-uyari').attr('data-tik') || $tikKaynagi.attr('data-tk-tik') || '';
    var tikSuruyor = false;
    var tikZamanlayici = null;

    function tikZamanla(ms) {
        window.clearTimeout(tikZamanlayici);
        tikZamanlayici = window.setTimeout(tikAt, ms);
    }

    function tikAt() {
        tikZamanlayici = null;

        if (!tikAdresi || tikSuruyor) { return; }

        tikSuruyor = true;

        $.ajax({ url: tikAdresi, method: 'POST', dataType: 'json', global: false, data: { csrf_token: CY.token() } })
            .done(function (r) {
                tikSuruyor = false;

                if (r && typeof r.bekleyen === 'number' && r.bekleyen > 0) {
                    tikZamanla(r.islenen ? 1500 : 10000);
                }
            })
            .fail(function () {
                tikSuruyor = false;
                tikZamanla(30000);
            });
    }

    function tarayicidanIsle(d) {
        if (!tikAdresi || tikSuruyor || tikZamanlayici !== null || !d || d.tarayici_isle !== true) { return; }

        tikAt();
    }

    if (tikAdresi && $tikKaynagi.attr('data-tk-tik-baslat') === '1') {
        tikZamanla(800);
    }

    /* Durum sekmesi: işçi komutunu panoya kopyala (Kopyala → Kopyalandı). */
    $(document).on('click', '.js-tk-kopyala', function () {
        var $d = $(this);
        var metin = $d.attr('data-kopya') || '';
        var bitti = function (tamam) {
            $d.find('.js-tk-kopya-metin').length || $d.append(' ');
            $d.attr('data-durum', tamam ? 'tamam' : 'hata');
            $d.contents().last()[0].textContent = tamam ? ' Kopyalandı' : ' Seçip kopyalayın';
            window.setTimeout(function () { $d.contents().last()[0].textContent = ' Kopyala'; }, 2000);
        };

        if (navigator.clipboard && window.isSecureContext !== false) {
            navigator.clipboard.writeText(metin).then(function () { bitti(true); }, function () { bitti(false); });
        } else {
            bitti(false);
        }
    });

    /* Sekme arka plandayken istek atılmaz; dönünce hemen bir kez bakılır. */
    function yokla(is) {
        var zamanlayici = null;

        function tur() {
            if (document.hidden) { return; }

            is(function devam(surdur) {
                if (!surdur) {
                    window.clearInterval(zamanlayici);
                    document.removeEventListener('visibilitychange', gorunurluk);
                }
            });
        }

        function gorunurluk() { if (!document.hidden) { tur(); } }

        zamanlayici = window.setInterval(tur, ARALIK_MS);
        document.addEventListener('visibilitychange', gorunurluk);
    }

    /* =================================================================
     *  1) YENİ ANALİZ FORMU
     * ============================================================== */
    var $kutu = $('#tk_analiz');

    if ($kutu.length) {
        var $url    = $('#tk_url');
        var $dugme  = $('#tk_analiz_dugme');
        var sonuc   = document.getElementById('tk_analiz_sonuc');

        var goster = function (tur, metin, eylemler) {
            sonuc.className = 'tk-analiz__sonuc is-' + tur;
            sonuc.textContent = '';
            sonuc.appendChild(el('span', 'tk-analiz__sonuc-metin', metin));
            (eylemler || []).forEach(function (eylem) { sonuc.appendChild(eylem); });
            sonuc.hidden = false;
        };

        var baglanti = function (metin, adres, sinif) {
            var a = el('a', 'btn cy-btn cy-btn--sm ' + (sinif || 'cy-btn--ghost'), metin);
            a.href = adres;
            return a;
        };

        var gonder = function (yeniden) {
            var url = $.trim($url.val());

            if (url === '') {
                goster('hata', 'Bir YouTube bağlantısı yapıştırın.');
                $url.trigger('focus');
                return;
            }

            $dugme.prop('disabled', true);

            $.ajax({
                url: $kutu.data('url'),
                method: 'POST',
                dataType: 'json',
                data: { url: url, yeniden: yeniden ? 1 : 0, csrf_token: CY.token() }
            }).done(function (res) {
                if (res.sonuc === 'onbellekten') {
                    var eylemler = [baglanti('Sonuçları Gör', res.adres, 'cy-btn--primary')];

                    // Sunucu söyler: yönetici ya da raporu başlatan (TranskriptPolicy::canReanalyze).
                    if (res.yeniden_analiz === true) {
                        var yenile = el('button', 'btn cy-btn cy-btn--ghost cy-btn--sm', 'Yeniden Analiz Et');
                        yenile.type = 'button';
                        yenile.addEventListener('click', function () {
                            onayla({
                                baslik: 'Yeniden analiz et',
                                metin: 'Bu video yeniden analiz edilecek; yapay zekâ çağrısı yapılır. Yeni rapor hazır olana kadar mevcut rapor görünmeye devam eder.',
                                dugme: 'Evet, yeniden analiz et'
                            }, function () { gonder(true); });
                        });
                        eylemler.push(yenile);
                    }

                    goster('uyari', res.description, eylemler);
                } else if (res.sonuc === 'suruyor') {
                    goster('bilgi', res.description, [baglanti('İlerlemeyi gör', res.adres, 'cy-btn--primary')]);
                } else {
                    goster('bilgi', res.description + ' Rapor sayfasına geçiliyor…');
                    window.location.href = res.adres;
                }
            }).fail(function (xhr, durum) {
                var res = xhr.responseJSON || {};

                /* Gönderim hiçbir zaman sessizce başarısız olmasın (cowork 3. tur):
                 * oturum / güvenlik anahtarı (CSRF) süresi dolduysa sebep kutuda
                 * kalır ve sayfa bağlantı kutuya YENİDEN YAZILMIŞ olarak açılır
                 * (?url=…); tek dokunuşla yeniden gönderilir. */
                if (res.expired === true || xhr.status === 401 || xhr.status === 419) {
                    goster('hata', 'Oturumunuzun güvenlik anahtarının süresi dolmuş; analiz başlatılmadı. Sayfa yenileniyor, bağlantı kutuda hazır olacak: "Analiz Et"e yeniden basın.');

                    window.setTimeout(function () {
                        window.location.href = window.location.pathname + '?url=' + encodeURIComponent(url) + '#tk_analiz';
                    }, 2500);
                    return;
                }

                goster('hata', res.description || (xhr.status === 0
                    ? 'Sunucuya ulaşılamadı; analiz başlatılmadı. Bağlantınızı kontrol edip yeniden deneyin.'
                    : durum === 'parsererror'
                        ? 'Sunucu beklenmeyen bir yanıt verdi; analiz başlatılmadı. Sayfayı yenileyip yeniden deneyin.'
                        : 'Analiz başlatılamadı (HTTP ' + xhr.status + '). Biraz sonra yeniden deneyin.'));
            }).always(function () {
                $dugme.prop('disabled', false);
            });
        };

        $('#tk_analiz_form').on('submit', function (olay) {
            olay.preventDefault();
            gonder(false);
        });

        // panel/transkript?url=… (yer imi): kutu dolu; tek dokunuş kalsın.
        if ($url.attr('data-hazir') === '1') {
            $dugme.trigger('focus');
        }
    }

    /* =================================================================
     *  2) LİSTE: süren analizlerin satırı
     * ============================================================== */
    if ($('tr[data-ilerleme]').length) {
        yokla(function (devam) {
            var $satirlar = $('tr[data-ilerleme]');

            if (!$satirlar.length) {
                devam(false);
                return;
            }

            $satirlar.each(function () {
                var $tr = $(this);

                $.getJSON($tr.attr('data-ilerleme')).done(function (d) {
                    var bitti = d.durum === 'tamamlandi' || d.durum === 'hata';

                    kuyrukUyarisi(d);

                    $tr.find('.js-tk-cubuk').css('width', d.ilerleme + '%').parent().attr('aria-valuenow', d.ilerleme);
                    $tr.find('.js-tk-etiket').text(d.etiket);

                    if (!bitti) { return; }

                    $tr.removeAttr('data-ilerleme');
                    $tr.find('.js-tk-iptal').remove();

                    var $durum  = $tr.find('.js-tk-durum').removeClass('is-info is-ok is-danger');
                    var $hucre  = $tr.find('.tk-durum-hucre');

                    $hucre.find('.tk-mini-ilerleme').remove();

                    if (d.durum === 'tamamlandi') {
                        $durum.addClass('is-ok').text('Tamamlandı');
                        $hucre.append($('<a class="cy-link small">').attr('href', $tr.find('.tk-kaynak').attr('href')).text('Rapor hazır'));
                    } else if (d.hata && d.hata.kod === 'iptal_edildi') {
                        $durum.text('İptal edildi');
                    } else {
                        $durum.addClass('is-danger').text('Hata');
                        $hucre.append($('<small class="tk-hata-metni">').text(d.hata ? d.hata.mesaj : d.etiket));
                    }
                });
            });
        });
    }

    /* =================================================================
     *  3) RAPOR: aşama ilerlemesi
     * ============================================================== */
    var ilerleme = document.getElementById('tk_ilerleme');

    if (ilerleme && ilerleme.getAttribute('data-izle') === '1') {
        var SIRA = ['kaynak', 'transkript', 'parca', 'birlestirme'];

        yokla(function (devam) {
            $.getJSON(ilerleme.getAttribute('data-url')).done(function (d) {
                var $k = $(ilerleme);
                var simdiki = SIRA.indexOf(d.asama === 'rapor' || d.asama === 'bitti' ? 'birlestirme' : d.asama);

                $k.find('.js-tk-etiket').text(d.etiket);
                $k.find('.js-tk-yuzde').text('%' + d.ilerleme);
                $k.find('.js-tk-cubuk').css('width', d.ilerleme + '%').parent().attr('aria-valuenow', d.ilerleme);
                $k.find('.js-tk-parca').text(d.parca && d.parca.toplam > 0
                    ? d.parca.biten + ' / ' + d.parca.toplam + (d.parca.hatali > 0 ? ' · ' + d.parca.hatali + ' hatalı' : '') : '');
                $k.find('.js-tk-sirada-metin').text(d.sirada || '');
                $k.find('.js-tk-sirada').prop('hidden', !d.sirada);
                kuyrukUyarisi(d);

                // Hatayla durduysa o aşama "tamamlandı" görünmesin: kırmızı.
                $k.find('.tk-asama').each(function (i) {
                    $(this).toggleClass('is-bitti', i < simdiki)
                        .toggleClass('is-suruyor', i === simdiki && d.durum !== 'hata')
                        .toggleClass('is-hata', i === simdiki && d.durum === 'hata');
                });

                if (d.durum === 'tamamlandi' || d.durum === 'hata') {
                    devam(false);

                    if (d.durum === 'tamamlandi') {
                        $k.find('.tk-asama').addClass('is-bitti').removeClass('is-suruyor');
                        CY.notify('Rapor hazır.', 'success');
                    }

                    window.setTimeout(function () { window.location.reload(); }, 900);
                }
            });
        });
    }

    /* =================================================================
     *  4) OYNATICI: zaman damgası → o saniye
     * =================================================================
     *  YouTube IFrame Player API'nin iletişim yolu postMessage'dır
     *  (enablejsapi=1). Komutu doğrudan göndermek dış betik (iframe_api)
     *  yüklemeyi gereksiz kılar; CSP'de script-src açılmaz.
     *  Oynatıcı görünmüyorsa (telefon) bağlantı varsayılan işini yapar:
     *  youtu.be/ID?t=… YouTube uygulamasını o saniyeden açar.
     */
    var oynatici = document.getElementById('tk_oynatici');

    var komut = function (ad, argumanlar) {
        if (oynatici && oynatici.contentWindow) {
            oynatici.contentWindow.postMessage(JSON.stringify({ event: 'command', func: ad, args: argumanlar || [] }), YOUTUBE);
        }
    };

    if (oynatici) {
        oynatici.addEventListener('load', function () {
            oynatici.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 'tk_oynatici', channel: 'widget' }), YOUTUBE);
        });
    }

    $(document).on('click', 'a.tk-zaman[data-sn]', function (olay) {
        if (!oynatici || oynatici.offsetParent === null) {
            return;
        }

        olay.preventDefault();

        komut('seekTo', [parseInt(this.getAttribute('data-sn'), 10) || 0, true]);
        komut('playVideo');

        $('a.tk-zaman.is-oynuyor').removeClass('is-oynuyor');
        $(this).addClass('is-oynuyor');

        var kutu = oynatici.getBoundingClientRect();

        if (kutu.top < 0 || kutu.bottom > window.innerHeight) {
            oynatici.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    /* =================================================================
     *  5) ÖNEM FİLTRESİ: "Yalnız kritik ve önemli"
     * ============================================================== */
    /* Rozet filtreyle değişir ("12 / 14"); tercih tarayıcıda hatırlanır.
     * localStorage gizli pencerede ya da kapalıyken hata verebilir: o
     * zaman yalnız hatırlanmaz, filtre yine çalışır. */
    var ONEM_ANAHTARI = 'transkript.onemFiltresi';

    function onemSuz(kutu) {
        var $bolum = $(kutu).closest('.js-tk-onemli').toggleClass('is-suzgec', kutu.checked);
        var sayac  = $bolum.find('.js-tk-onem-sayac')[0];

        if (sayac) {
            sayac.textContent = kutu.checked
                ? sayac.getAttribute('data-suzgecli') + ' / ' + sayac.getAttribute('data-toplam')
                : sayac.getAttribute('data-toplam');
        }
    }

    $(document).on('change', '.js-tk-onem-filtre', function () {
        onemSuz(this);

        try { window.localStorage.setItem(ONEM_ANAHTARI, this.checked ? '1' : '0'); } catch (e) { /* hatırlanmaz */ }
    });

    $('.js-tk-onem-filtre').each(function () {
        var kayitli = null;

        try { kayitli = window.localStorage.getItem(ONEM_ANAHTARI); } catch (e) { /* yok sayılır */ }

        if (kayitli === '1') {
            this.checked = true;
            onemSuz(this);
        }
    });

    /* =================================================================
     *  6) YENİDEN ANALİZ / YENİDEN DENE (rapor sayfası)
     * ============================================================== */
    $(document).on('click', '.js-tk-yeniden', function () {
        var $b = $(this);

        dugmeOnayi(this, function () {
            $b.prop('disabled', true);

            $.ajax({
                url: $b.attr('data-url'),
                method: 'POST',
                dataType: 'json',
                data: { url: $b.attr('data-video'), yeniden: $b.attr('data-yeniden') === '1' ? 1 : 0, csrf_token: CY.token() }
            }).done(function (res) {
                CY.notify(res.description, 'success');
                window.setTimeout(function () { window.location.href = res.adres; }, 600);
            }).fail(function (xhr) {
                CY.ajaxError(xhr, 'Analiz başlatılamadı.');
                $b.prop('disabled', false);
            });
        });
    });

    /* =================================================================
     *  10) KAYIT: iptal ve silme (liste satırı ve rapor sayfası)
     * ============================================================== */
    function kayitIstegi(dugme, hata, bitti, ek) {
        var $b = $(dugme);

        dugmeOnayi(dugme, function () {
            $b.prop('disabled', true);

            $.ajax({
                url: $b.attr('data-url'),
                method: 'POST',
                dataType: 'json',
                data: $.extend({ csrf_token: CY.token() }, ek || {})
            }).done(function (res) {
                CY.notify(res.description, 'success');
                bitti($b, res);
            }).fail(function (xhr) {
                CY.ajaxError(xhr, hata);
                $b.prop('disabled', false);
            });
        });
    }

    $(document).on('click', '.js-tk-iptal', function () {
        kayitIstegi(this, 'Analiz iptal edilemedi.', function () {
            window.setTimeout(function () { window.location.reload(); }, 600);
        });
    });

    $(document).on('click', '.js-tk-sil', function () {
        kayitIstegi(this, 'Kayıt silinemedi.', function ($b, res) {
            var $tr = $b.closest('tr');

            // Listede satır kalkar; rapor sayfasından listeye dönülür.
            if ($tr.length) {
                $tr.fadeOut(200, function () { $tr.remove(); });
            } else {
                window.setTimeout(function () { window.location.href = res.adres; }, 600);
            }
        });
    });

    /* Kütüphanemden çıkar: ortak rapor kalır. Paylaşılan rapor listede
     * görünmeye devam ettiği için (res.gorunur) sayfa yenilenir. */
    $(document).on('click', '.js-tk-cikar', function () {
        kayitIstegi(this, 'Video kütüphaneden çıkarılamadı.', function ($b, res) {
            var $tr = $b.closest('tr');

            if (res.gorunur === true) {
                window.setTimeout(function () { window.location.reload(); }, 600);
            } else if ($tr.length) {
                $tr.fadeOut(200, function () { $tr.remove(); });
            } else {
                window.setTimeout(function () { window.location.href = res.adres; }, 600);
            }
        });
    });

    /* Onaylı form gönderimi (ayarlar: "Dinlenmeleri sıfırla"). Onaydan
     * sonra form.submit() çağrılır; tıklama olayı yeniden tetiklenmez. */
    $(document).on('click', '.js-tk-form-onay', function (e) {
        var form = this.form;

        if (!form) { return; }

        e.preventDefault();
        dugmeOnayi(this, function () { form.submit(); });
    });

    /* Üyelerle paylaş / paylaşımı kaldır (yalnız yönetici). */
    $(document).on('click', '.js-tk-paylas', function () {
        kayitIstegi(this, 'Paylaşım değiştirilemedi.', function () {
            window.setTimeout(function () { window.location.reload(); }, 600);
        }, { paylas: $(this).attr('data-paylas') === '1' ? 1 : 0 });
    });

    /* =================================================================
     *  9) "ŞİMDİ İŞLE": işçi yokken sıradaki işleri istekte çalıştırır
     * =================================================================
     *  Yalnız yöneticiye ve geliştirme ortamında görünür (sunucu da
     *  denetler). İstek bir AI adımı sürerse 1–2 dakika bekler; bitince
     *  sayfa yenilenir ve ilerleme yeni durumdan devam eder. */
    $(document).on('click', '.js-tk-kuyruk-isle', function () {
        var $b = $(this);
        var $metin = $b.find('.js-tk-isle-metin');
        var eski = $metin.text();

        $b.prop('disabled', true);
        $metin.text('İşleniyor…');

        $.ajax({
            url: $b.attr('data-url'),
            method: 'POST',
            dataType: 'json',
            data: { csrf_token: CY.token() }
        }).done(function (res) {
            CY.notify(res.description, 'success');
            window.setTimeout(function () { window.location.reload(); }, 700);
        }).fail(function (xhr) {
            CY.ajaxError(xhr, 'Kuyruk işlenemedi.');
            $b.prop('disabled', false);
            $metin.text(eski);
        });
    });

    /* =================================================================
     *  7) TRANSCRIPT: yükleme ve arama
     * ============================================================== */
    var transcript = document.getElementById('tk_transcript');

    /* Türkçe katlama (Search::trFold ile aynı): "IŞIK" ~ "isik".
     * Her karakter TEK karaktere katlanır; böylece katlanmış metindeki
     * konum özgün metindeki konumla aynı kalır ve vurgu doğru yere düşer. */
    var KATLA = {
        'ç': 'c', 'Ç': 'c', 'ğ': 'g', 'Ğ': 'g', 'ı': 'i', 'I': 'i', 'İ': 'i', 'ö': 'o', 'Ö': 'o',
        'ş': 's', 'Ş': 's', 'ü': 'u', 'Ü': 'u', 'â': 'a', 'Â': 'a', 'î': 'i', 'Î': 'i', 'û': 'u', 'Û': 'u'
    };

    function katla(metin) {
        var cikti = '';

        for (var i = 0; i < metin.length; i++) {
            var harf = metin.charAt(i);
            cikti += KATLA[harf] || harf.toLowerCase().charAt(0);
        }

        return cikti;
    }

    /* Eşleşen kısımları <mark> içine alır; metin hep textContent. */
    function vurgula(hedef, metin, kelimeler) {
        var katli = katla(metin);
        var araliklar = [];

        kelimeler.forEach(function (kelime) {
            var k = katla(String(kelime));

            if (k.length < 2) { return; }

            for (var i = katli.indexOf(k); i !== -1; i = katli.indexOf(k, i + k.length)) {
                araliklar.push([i, i + k.length]);
            }
        });

        araliklar.sort(function (a, b) { return a[0] - b[0]; });

        var konum = 0;

        araliklar.forEach(function (aralik) {
            if (aralik[0] < konum) { return; }   // üst üste binen eşleşme

            hedef.appendChild(document.createTextNode(metin.slice(konum, aralik[0])));
            hedef.appendChild(el('mark', '', metin.slice(aralik[0], aralik[1])));
            konum = aralik[1];
        });

        hedef.appendChild(document.createTextNode(metin.slice(konum)));
    }

    if (transcript) {
        var rapor      = document.getElementById('tk_rapor');
        var adres      = rapor.getAttribute('data-transkript-url');
        var video      = rapor.getAttribute('data-video');
        var liste      = transcript.querySelector('.js-tk-transcript-liste');
        var durumYazi  = transcript.querySelector('.js-tk-transcript-durum');
        var aramaKutu  = transcript.querySelector('.js-tk-ara');
        var seq        = 0;
        var bekleme    = null;
        var yuklendi   = false;
        var aktifSn    = -1;
        var takip      = transcript.querySelector('.js-tk-takip');

        /* 8) Oynatıcı zamanı: YouTube, "listening" el sıkışmasından sonra
         * postMessage ile infoDelivery.currentTime gönderir. Yalnız
         * youtube-nocookie kökeninden gelen ileti dinlenir. O anki satır
         * işaretlenir; "takip" açıksa liste (sayfa değil) kaydırılır. */
        window.addEventListener('message', function (olay) {
            if (olay.origin !== YOUTUBE || !transcript.open || !takip || !takip.checked) { return; }

            var veri;

            try { veri = JSON.parse(olay.data); } catch (e) { return; }

            var an = veri && veri.info && typeof veri.info.currentTime === 'number' ? veri.info.currentTime : null;

            if (an === null) { return; }

            var secilen = null;

            liste.querySelectorAll('li[data-sn]').forEach(function (li) {
                if (parseInt(li.getAttribute('data-sn'), 10) <= an) { secilen = li; }
            });

            if (!secilen) { return; }

            var sn = parseInt(secilen.getAttribute('data-sn'), 10);

            if (sn === aktifSn) { return; }

            aktifSn = sn;

            var eski = liste.querySelector('li.is-aktif');

            if (eski) { eski.classList.remove('is-aktif'); }

            secilen.classList.add('is-aktif');
            liste.scrollTop = secilen.offsetTop - liste.offsetTop - liste.clientHeight / 3;
        });

        var zamanOgesi = function (sn, metin) {
            if (!video) { return el('span', 'tk-zaman', metin); }

            var a = el('a', 'tk-zaman', metin);
            a.href = 'https://youtu.be/' + encodeURIComponent(video) + (sn > 0 ? '?t=' + sn : '');
            a.setAttribute('data-sn', String(sn));
            a.target = '_blank';
            a.rel = 'noopener';
            return a;
        };

        var ciz = function (satirlar, kelimeler) {
            var parca = document.createDocumentFragment();

            satirlar.forEach(function (s) {
                var li = document.createElement('li');
                li.setAttribute('data-sn', String(s.sn));
                var metin = document.createElement('span');

                vurgula(metin, String(s.metin), kelimeler);
                li.appendChild(zamanOgesi(s.sn, s.zaman));
                li.appendChild(metin);
                parca.appendChild(li);
            });

            liste.textContent = '';
            liste.appendChild(parca);
            aktifSn = -1;
        };

        var yukle = function (q) {
            var benim = ++seq;

            durumYazi.textContent = q ? 'Aranıyor…' : 'Yükleniyor…';

            $.getJSON(adres, q ? { q: q } : {}).done(function (res) {
                if (benim !== seq) { return; }   // daha yeni bir istek var; eski yanıt atılır

                var satirlar = res.satirlar || [];

                ciz(satirlar, res.kelimeler || []);
                durumYazi.textContent = res.arama && !satirlar.length ? 'Eşleşme yok.' : res.description;
            }).fail(function () {
                if (benim !== seq) { return; }

                // Bir sonraki açılışta yeniden denensin; şimdi de elle denenebilir.
                yuklendi = false;
                durumYazi.textContent = 'Transcript yüklenemedi. ';

                var tekrar = el('button', 'btn btn-link p-0 align-baseline cy-link js-tk-transcript-tekrar', 'Tekrar dene');
                tekrar.type = 'button';
                tekrar.addEventListener('click', function () {
                    yuklendi = true;
                    yukle($.trim(aramaKutu.value).length >= 2 ? $.trim(aramaKutu.value) : '');
                });
                durumYazi.appendChild(tekrar);
            });
        };

        transcript.addEventListener('toggle', function () {
            if (transcript.open && !yuklendi) {
                yuklendi = true;
                yukle('');
            }
        });

        aramaKutu.addEventListener('input', function () {
            var q = $.trim(aramaKutu.value);

            window.clearTimeout(bekleme);

            // Tek harf aranmaz (FULLTEXT / LIKE anlamsız sonuç verir): istek yok, açıklama var.
            if (q.length === 1) {
                seq++;
                durumYazi.textContent = 'En az 2 harf yazın.';
                return;
            }

            bekleme = window.setTimeout(function () {
                yuklendi = true;
                yukle(q);
            }, 300);
        });
    }

    /* =================================================================
     *  11) AYARLAR: sekmeler (Üyelik · Yapay zekâ · Bütçe · Site · Durum)
     * =================================================================
     *  JS yoksa bütün bölümler alt alta görünür (sekmeler çapadır).
     *  Seçili sekme adreste durur (#uyelik) ve formla gönderilir; kayıttan
     *  sonra aynı sekme açılır. Sunucu hatalı alanın sekmesini data-ilk
     *  ile söyler, o önce açılır. Ok tuşlarıyla sekmeler arasında gezilir. */
    (function () {
        var nav = document.querySelector('.js-tk-sekmeler');

        if (!nav) { return; }

        var sekmeler = Array.prototype.slice.call(nav.querySelectorAll('[role="tab"]'));
        var gizli    = document.querySelector('.js-tk-sekme-girdi');
        var kaydet   = document.querySelector('.js-tk-kaydet');

        function sec(ad) {
            var bulundu = sekmeler.some(function (s) { return s.getAttribute('aria-controls') === ad; });

            if (!bulundu) { return false; }

            sekmeler.forEach(function (s) {
                var etkin = s.getAttribute('aria-controls') === ad;
                var panel = document.getElementById(s.getAttribute('aria-controls'));

                s.setAttribute('aria-selected', etkin ? 'true' : 'false');
                s.tabIndex = etkin ? 0 : -1;
                s.classList.toggle('is-etkin', etkin);

                if (panel) { panel.hidden = !etkin; }
            });

            if (gizli) { gizli.value = ad; }
            // "Durum" sekmesinde kaydedilecek ayar yok.
            if (kaydet) { kaydet.hidden = ad === 'durum'; }

            return true;
        }

        nav.classList.add('is-hazir');

        var ilk  = nav.getAttribute('data-ilk') || '';
        var adres = (window.location.hash || '').slice(1);

        if (!(ilk && sec(ilk)) && !(adres && sec(adres))) {
            sec(sekmeler[0].getAttribute('aria-controls'));
        }

        nav.addEventListener('click', function (e) {
            var s = e.target.closest('[role="tab"]');

            if (!s) { return; }

            e.preventDefault();
            sec(s.getAttribute('aria-controls'));

            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + s.getAttribute('aria-controls'));
            }
        });

        nav.addEventListener('keydown', function (e) {
            var i = sekmeler.indexOf(document.activeElement);

            if (i < 0 || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }

            e.preventDefault();
            var yeni = sekmeler[(i + (e.key === 'ArrowRight' ? 1 : sekmeler.length - 1)) % sekmeler.length];
            yeni.click();
            yeni.focus();
        });
    }());

    /* =================================================================
     *  11b) Sol menüdeki "Yeni analiz" (adres#tk_analiz): bağlantı
     *  kutusuna odaklanır. Aynı sayfadayken yalnız adres parçası
     *  değişir; hashchange de dinlenir.
     * ================================================================= */
    (function () {
        var kutu = document.getElementById('tk_url');

        if (!kutu) { return; }

        function odaklan() {
            if (window.location.hash === '#tk_analiz') {
                kutu.focus({ preventScroll: false });
            }
        }

        odaklan();
        window.addEventListener('hashchange', odaklan);
    }());

    /* =================================================================
     *  12) LİSTE: telefonda süzgeçler "Filtreler" düğmesinin arkasında
     * =================================================================
     *  Arama kutusu hep görünür; durum / kapsam / kişi seçimleri ve
     *  "Uygula" düğmeyle açılır (CSS yalnız dar ekranda gizler). Etkin
     *  süzgeç varsa sunucu formu açık çizer. JS yoksa hepsi görünür. */
    (function () {
        var form  = document.querySelector('.js-tk-araclar');
        var dugme = form ? form.querySelector('.js-tk-filtre-ac') : null;

        if (!form || !dugme) { return; }

        form.classList.add('js-hazir');
        dugme.hidden = false;
        dugme.addEventListener('click', function () {
            var acik = !form.classList.contains('is-acik');

            form.classList.toggle('is-acik', acik);
            dugme.setAttribute('aria-expanded', acik ? 'true' : 'false');
        });
    }());
}(window.jQuery));
