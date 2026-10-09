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

    /* İlerleme yanıtındaki kuyruk_takili → "Kuyruk işçisi çalışmıyor" uyarısı. */
    function kuyrukUyarisi(d) {
        if (d && typeof d.kuyruk_takili === 'boolean') {
            $('.js-tk-kuyruk-uyari').prop('hidden', !d.kuyruk_takili);
        }

        tarayicidanIsle(d);
    }

    /* Komut satırısız kuyruk: işçi yokken (tarayici_isle) sunucu, kilitle TEK
     * bir işi çalıştırır. Aynı anda bu sekmeden tek istek; yanıtı beklenmez,
     * ilerleme yoklaması sürer. global:false → kapalıysa hata bildirimi yok. */
    var tikAdresi = $('.js-tk-kuyruk-uyari').attr('data-tik') || '';
    var tikSuruyor = false;

    function tarayicidanIsle(d) {
        if (!tikAdresi || tikSuruyor || !d || d.tarayici_isle !== true) { return; }

        tikSuruyor = true;

        $.ajax({ url: tikAdresi, method: 'POST', dataType: 'json', global: false, data: { csrf_token: CY.token() } })
            .always(function () { tikSuruyor = false; });
    }

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

                    if (String($kutu.data('yeniden')) === '1') {
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
            }).fail(function (xhr) {
                var res = xhr.responseJSON || {};

                // Oturum düştüyse app.js uyarır ve sayfayı yeniler.
                if (res.expired === true || xhr.status === 401 || xhr.status === 419) {
                    CY.ajaxError(xhr);
                    return;
                }

                goster('hata', res.description || 'Analiz başlatılamadı. Biraz sonra yeniden deneyin.');
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
    function kayitIstegi(dugme, hata, bitti) {
        var $b = $(dugme);

        dugmeOnayi(dugme, function () {
            $b.prop('disabled', true);

            $.ajax({
                url: $b.attr('data-url'),
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: CY.token() }
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
}(window.jQuery));
