/* ==================================================================
 *  TRANSKRİPT — ANA SAYFA hareketi (views/anasayfa.php)
 * ------------------------------------------------------------------
 *  Bağımlılıksız. Sayfa JavaScript olmadan da eksiksizdir: önizleme
 *  bitmiş raporu gösterir, vitrinin bütün sekmeleri alt alta açıktır,
 *  bölümler baştan görünür. "Hareketi azalt" tercihinde hiçbir döngü
 *  başlamaz; vitrin sekmeleri yine elle çalışır.
 *
 *    1) Görünür olunca belirme ve sayaçlar
 *    2) Başlıkta dönen kelime
 *    3) Ürün önizlemesi: bağlantı yazılır → adımlar ilerler → rapor
 *    4) Rapor vitrini: ARIA sekmeleri, kendiliğinden ilerleme
 *    5) Kartlarda fareyi izleyen ışık, sayfa içi bağlantılar
 *
 *  Metinler yalnız textContent ile yazılır (innerHTML yok).
 *  Ekranda olmayan ya da arka plandaki sekmede döngüler durur.
 * ================================================================== */
(function () {
    'use strict';

    var azalt = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var gozcuVar = 'IntersectionObserver' in window;

    /* Ekranda mı? (gözcü yoksa hep görünür sayılır) */
    function gozle(oge, geriCagri, secenek) {
        if (!gozcuVar) {
            geriCagri(true);
            return;
        }

        new IntersectionObserver(function (girdiler) {
            girdiler.forEach(function (g) { geriCagri(g.isIntersecting); });
        }, secenek || { threshold: 0.15 }).observe(oge);
    }

    /* ---------------------------------------------------------------
     *  1) BELİRME VE SAYAÇLAR
     * ------------------------------------------------------------ */
    function say(oge) {
        var hedef = parseInt(oge.getAttribute('data-tka-say'), 10);

        if (!(hedef > 0) || azalt || !window.requestAnimationFrame) {
            return;
        }

        var bas = null;
        var sure = 1300;

        oge.textContent = '0';

        function kare(an) {
            if (bas === null) { bas = an; }
            var ilerleme = Math.min(1, (an - bas) / sure);
            var yumusak = 1 - Math.pow(1 - ilerleme, 3);

            oge.textContent = String(Math.round(hedef * yumusak));

            if (ilerleme < 1) { window.requestAnimationFrame(kare); }
        }

        window.requestAnimationFrame(kare);
    }

    (function belirme() {
        var ogeler = Array.prototype.slice.call(document.querySelectorAll('[data-tka-belir]'));

        if (azalt || !gozcuVar || !ogeler.length) {
            return;
        }

        document.documentElement.classList.add('tka-hazir');

        var gozcu = new IntersectionObserver(function (girdiler) {
            girdiler.forEach(function (g) {
                if (!g.isIntersecting) { return; }

                var oge = g.target;
                var gecikme = parseFloat(window.getComputedStyle(oge).transitionDelay) || 0;

                gozcu.unobserve(oge);
                oge.classList.add('is-gorunur');
                Array.prototype.forEach.call(oge.querySelectorAll('[data-tka-say]'), say);

                /* Belirme bitince öznitelik kalkar: kartın kendi hover
                 * geçişleri (gölge, kenarlık) geri gelir, gecikme kalmaz. */
                window.setTimeout(function () { oge.removeAttribute('data-tka-belir'); }, 1000 + gecikme * 1000);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

        ogeler.forEach(function (oge) { gozcu.observe(oge); });
    }());

    /* ---------------------------------------------------------------
     *  2) DÖNEN KELİME ("Konferansları dakikalar içinde okuyun.")
     * ------------------------------------------------------------ */
    (function donenKelime() {
        var oge = document.querySelector('[data-tka-donen]');
        var kelimeler = [];

        if (!oge || azalt) { return; }

        try {
            kelimeler = JSON.parse(oge.getAttribute('data-tka-donen') || '[]');
        } catch (hata) {
            return;
        }

        if (kelimeler.length < 2) { return; }

        var sira = 0;
        var gorunur = true;

        gozle(oge, function (v) { gorunur = v; }, { threshold: 0 });

        window.setInterval(function () {
            if (document.hidden || !gorunur) { return; }

            oge.classList.add('is-cikis');

            window.setTimeout(function () {
                sira = (sira + 1) % kelimeler.length;
                oge.textContent = String(kelimeler[sira]);
                oge.classList.remove('is-cikis');
                oge.classList.add('is-giris');
                void oge.offsetWidth; // geçişsiz başlangıç konumunu uygula
                oge.classList.remove('is-giris');
            }, 360);
        }, 2800);
    }());

    /* ---------------------------------------------------------------
     *  3) ÜRÜN ÖNİZLEMESİ
     * ---------------------------------------------------------------
     *  data-sahne: 1 bağlantı yazılıyor, 2 adımlar (data-adim 1–5),
     *  3 rapor. Zamanlayıcı duraklatılabilir: ekrandan çıkınca ya da
     *  sekme arka plana geçince kalan süre saklanır, dönünce sürer.
     * ------------------------------------------------------------ */
    (function onizleme() {
        var demo = document.querySelector('[data-tka-demo]');

        if (!demo || azalt) { return; }

        var url = demo.querySelector('[data-tka-yaz]');
        var dugme = demo.querySelector('.tka-demo__dugme');
        var tamUrl = url ? url.textContent : '';

        var bekleyen = null;
        var kalan = 0;
        var baslangic = 0;
        var saat = null;
        var durdu = true;
        var gorunur = false;

        function calistir() {
            baslangic = Date.now();
            saat = window.setTimeout(function () {
                var is = bekleyen;

                bekleyen = null;
                saat = null;
                is();
            }, kalan);
        }

        function zamanla(is, ms) {
            bekleyen = is;
            kalan = ms;

            if (!durdu) { calistir(); }
        }

        function duraklat() {
            if (durdu) { return; }

            durdu = true;

            if (saat !== null) {
                window.clearTimeout(saat);
                saat = null;
                kalan = Math.max(0, kalan - (Date.now() - baslangic));
            }
        }

        function surdur() {
            if (!durdu) { return; }

            durdu = false;

            if (bekleyen && saat === null) { calistir(); }
        }

        function guncelle() {
            if (gorunur && !document.hidden) {
                surdur();
            } else {
                duraklat();
            }
        }

        function sahneBir() {
            var n = 0;

            demo.setAttribute('data-sahne', '1');
            demo.setAttribute('data-adim', '0');
            url.textContent = '';

            function yaz() {
                n += 1;
                url.textContent = tamUrl.slice(0, n);

                if (n < tamUrl.length) {
                    zamanla(yaz, 34 + Math.round(Math.random() * 46));
                } else {
                    zamanla(bas, 520);
                }
            }

            zamanla(yaz, 650);
        }

        function bas() {
            dugme.classList.add('is-basildi');
            zamanla(function () {
                dugme.classList.remove('is-basildi');
                sahneIki(1);
            }, 230);
        }

        function sahneIki(adim) {
            demo.setAttribute('data-sahne', '2');
            demo.setAttribute('data-adim', String(adim));

            if (adim < 5) {
                zamanla(function () { sahneIki(adim + 1); }, adim === 3 ? 1600 : 950);
            } else {
                zamanla(sahneUc, 550);
            }
        }

        function sahneUc() {
            demo.setAttribute('data-sahne', '3');
            zamanla(bitir, 7600);
        }

        function bitir() {
            demo.classList.add('is-sifir');
            zamanla(function () {
                demo.classList.remove('is-sifir');
                sahneBir();
            }, 480);
        }

        if (!url || !dugme || !tamUrl) { return; }

        /* İlk açılışta bitmiş rapor görünür; kısa süre sonra döngü başlar. */
        zamanla(bitir, 2600);

        gozle(demo, function (v) { gorunur = v; guncelle(); });
        document.addEventListener('visibilitychange', guncelle);
    }());

    /* ---------------------------------------------------------------
     *  4) RAPOR VİTRİNİ (ARIA sekmeleri)
     * ---------------------------------------------------------------
     *  Seçili sekmenin altındaki çubuk dolunca (CSS animasyonu, 7 sn)
     *  sıradakine geçilir; fare üstündeyken, odak içerideyken ya da
     *  ekran dışındayken çubuk durur. Kullanıcı bir sekme seçince
     *  kendiliğinden ilerleme biter.
     * ------------------------------------------------------------ */
    (function vitrin() {
        var kutu = document.querySelector('[data-tka-vitrin]');

        if (!kutu) { return; }

        var sekmeler = Array.prototype.slice.call(kutu.querySelectorAll('[role="tab"]'));
        var paneller = sekmeler.map(function (s) { return document.getElementById(s.getAttribute('aria-controls')); });
        var liste = sekmeler.length ? sekmeler[0].parentNode : null;
        var aktif = 0;
        var gorunur = false;
        var ustunde = false;
        var odakta = false;

        if (!sekmeler.length || paneller.indexOf(null) !== -1) { return; }

        function durum() {
            kutu.classList.toggle('is-durdu', !gorunur || ustunde || odakta || document.hidden);
        }

        function kaydir(sekme) {
            if (liste.scrollWidth <= liste.clientWidth + 1) { return; }

            var fark = sekme.getBoundingClientRect().left - liste.getBoundingClientRect().left;

            liste.scrollTo({ left: liste.scrollLeft + fark - 16, behavior: azalt ? 'auto' : 'smooth' });
        }

        function sec(sira, odakla) {
            aktif = (sira + sekmeler.length) % sekmeler.length;

            sekmeler.forEach(function (s, j) {
                var secili = j === aktif;

                s.setAttribute('aria-selected', secili ? 'true' : 'false');
                s.tabIndex = secili ? 0 : -1;
                paneller[j].hidden = !secili;
            });

            var panel = paneller[aktif];

            panel.classList.remove('is-giris');

            if (!azalt) {
                void panel.offsetWidth;
                panel.classList.add('is-giris');
            }

            if (odakla) { sekmeler[aktif].focus(); }

            kaydir(sekmeler[aktif]);
        }

        function elle(sira, odakla) {
            kutu.classList.remove('is-oto');
            sec(sira, odakla);
        }

        sekmeler.forEach(function (sekme, j) {
            sekme.addEventListener('click', function () { elle(j, false); });

            sekme.addEventListener('keydown', function (olay) {
                var hedef = null;

                switch (olay.key) {
                    case 'ArrowRight': case 'ArrowDown': hedef = aktif + 1; break;
                    case 'ArrowLeft':  case 'ArrowUp':   hedef = aktif - 1; break;
                    case 'Home': hedef = 0; break;
                    case 'End':  hedef = sekmeler.length - 1; break;
                }

                if (hedef !== null) {
                    olay.preventDefault();
                    elle(hedef, true);
                }
            });

            sekme.querySelector('.tka-sekme__sure').addEventListener('animationend', function () {
                if (kutu.classList.contains('is-oto') && j === aktif) { sec(aktif + 1, false); }
            });
        });

        kutu.classList.add('is-hazir');
        paneller.forEach(function (p, j) { p.hidden = j !== aktif; });

        if (!azalt) { kutu.classList.add('is-oto'); }

        kutu.addEventListener('mouseenter', function () { ustunde = true; durum(); });
        kutu.addEventListener('mouseleave', function () { ustunde = false; durum(); });
        kutu.addEventListener('focusin', function () { odakta = true; durum(); });
        kutu.addEventListener('focusout', function (olay) {
            odakta = kutu.contains(olay.relatedTarget);
            durum();
        });
        document.addEventListener('visibilitychange', durum);
        gozle(kutu, function (v) { gorunur = v; durum(); }, { threshold: 0.35 });
        durum();
    }());

    /* ---------------------------------------------------------------
     *  5) KART IŞIĞI VE SAYFA İÇİ BAĞLANTILAR
     * ------------------------------------------------------------ */
    if (!azalt && window.matchMedia && window.matchMedia('(hover: hover)').matches) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-tka-isik]'), function (kart) {
            kart.addEventListener('pointermove', function (olay) {
                var r = kart.getBoundingClientRect();

                kart.style.setProperty('--mx', Math.round(olay.clientX - r.left) + 'px');
                kart.style.setProperty('--my', Math.round(olay.clientY - r.top) + 'px');
            });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.tka a[href^="#"]'), function (baglanti) {
        baglanti.addEventListener('click', function (olay) {
            var hedef = document.getElementById(baglanti.getAttribute('href').slice(1));

            if (!hedef) { return; }

            olay.preventDefault();
            hedef.scrollIntoView({ behavior: azalt ? 'auto' : 'smooth', block: 'start' });

            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + hedef.id);
            }
        });
    });
}());
