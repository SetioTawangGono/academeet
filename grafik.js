/* Grafik area dashboard (F12 revisi) — vanilla JS, tanpa library.
   Dropdown rentang, tooltip, titik aktif, keyboard, aria-live. Teks lewat textContent (bukan innerHTML). */
(function () {
  'use strict';

  var DESC = {
    '90d': 'Menampilkan total pendaftar 3 bulan terakhir',
    '30d': 'Menampilkan total pendaftar 30 hari terakhir',
    '7d': 'Menampilkan total pendaftar 7 hari terakhir'
  };

  function initCard(card) {
    var select = card.querySelector('[data-rentang]');
    var desc = card.querySelector('[data-desc]');
    var panels = card.querySelectorAll('.paketd-chart-panel');

    function tampilkan(rentang) {
      panels.forEach(function (p) {
        var aktif = p.id === 'panel' + rentang.replace('d', '');
        if (aktif) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', 'hidden'); }
        if (aktif) { aktifkanPanel(p); }
      });
      if (desc && DESC[rentang]) { desc.textContent = DESC[rentang]; }
    }

    if (select) {
      select.addEventListener('change', function () { tampilkan(select.value); });
    }

    // Inisialisasi panel aktif (90d) + panel lain saat pertama dibuka.
    tampilkan(select ? select.value : '90d');

    panels.forEach(function (p) { aktifkanPanel(p); });
  }

  /* Pasang interaksi ke satu panel grafik. */
  function aktifkanPanel(panel) {
    if (panel.dataset.siap === '1') { return; }
    var svg = panel.querySelector('[data-grafik]');
    var hit = panel.querySelector('[data-hit]');
    var tip = panel.querySelector('[data-tooltip]');
    var live = panel.querySelector('[data-live]');
    var jsonEl = panel.querySelector('[data-serie]');
    if (!svg || !hit || !jsonEl) { return; }

    var serie = [];
    try { serie = JSON.parse(jsonEl.textContent); } catch (e) { serie = []; }
    if (!serie.length) { return; }
    panel.dataset.siap = '1';

    // Bentuk jalur (polyline) untuk posisi titik kecil aktif.
    var jalur = jalurTitik(serie, svg);
    var gAktif = svg.querySelector('[data-titik-aktif]');
    var dotTop = svg.querySelector('.paketd-dot-top');
    var dotBot = svg.querySelector('.paketd-dot-bot');
    var idx = -1;

    function pindah(i, umumkan) {
      if (i < 0) { i = 0; }
      if (i > serie.length - 1) { i = serie.length - 1; }
      idx = i;
      var d = serie[i];
      // Titik kecil pada garis seri.
      if (gAktif && dotTop && dotBot) {
        var pos = posisiTitik(jalur, d, svg);
        dotTop.setAttribute('cx', pos.x);
        dotTop.setAttribute('cy', pos.yHadir);
        dotBot.setAttribute('cx', pos.x);
        dotBot.setAttribute('cy', pos.yTotal);
        gAktif.removeAttribute('hidden');
      }
      // Tooltip (textContent saja).
      if (tip) {
        tip.textContent = '';
        var judul = document.createElement('div');
        judul.className = 'paketd-tip-title';
        judul.textContent = tanggalPanjang(d.tanggal);
        tip.appendChild(judul);

        tip.appendChild(barisTip('--chart-1', 'Belum hadir', d.belum));
        tip.appendChild(barisTip('--chart-2', 'Hadir', d.hadir));

        var barisTotal = document.createElement('div');
        barisTotal.className = 'paketd-tip-row paketd-tip-row--total';
        var lblT = document.createElement('span');
        lblT.className = 'paketd-tip-label';
        lblT.textContent = 'Total';
        var valT = document.createElement('span');
        valT.className = 'paketd-tip-val';
        valT.textContent = String(d.total);
        barisTotal.appendChild(lblT);
        barisTotal.appendChild(valT);
        tip.appendChild(barisTotal);

        tip.removeAttribute('hidden');
        tip.setAttribute('aria-hidden', 'false');
        // Jaga tooltip di dalam kontainer (geser sedikit dari tepi).
        var w = panel.clientWidth || 1;
        var px = (d.x / 100) * w;
        tip.style.left = Math.min(Math.max(px, 8), w - 8) + 'px';
        tip.style.transform = 'translateX(-50%)';
      }
      if (umumkan && live) {
        live.textContent = tanggalPanjang(d.tanggal) + ': ' + d.hadir + ' hadir, ' + d.belum + ' belum hadir, total ' + d.total;
      }
    }

    function sembunyikan() {
      if (tip) { tip.setAttribute('hidden', 'hidden'); tip.setAttribute('aria-hidden', 'true'); }
      if (gAktif) { gAktif.setAttribute('hidden', 'hidden'); }
      idx = -1;
    }

    function dariX(klienX) {
      var rect = hit.getBoundingClientRect();
      if (rect.width <= 0) { return 0; }
      var frac = (klienX - rect.left) / rect.width;
      frac = Math.min(1, Math.max(0, frac));
      return Math.round(frac * (serie.length - 1));
    }

    hit.addEventListener('pointermove', function (e) { pindah(dariX(e.clientX), false); });
    hit.addEventListener('pointerdown', function (e) { pindah(dariX(e.clientX), true); });
    hit.addEventListener('pointerleave', function () { sembunyikan(); });
    hit.addEventListener('pointercancel', function () { sembunyikan(); });

    svg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { pindah(idx < 0 ? 0 : idx + 1, true); e.preventDefault(); }
      else if (e.key === 'ArrowLeft') { pindah(idx < 0 ? 0 : idx - 1, true); e.preventDefault(); }
      else if (e.key === 'Home') { pindah(0, true); e.preventDefault(); }
      else if (e.key === 'End') { pindah(serie.length - 1, true); e.preventDefault(); }
      else if (e.key === 'Escape') { sembunyikan(); }
    });
    svg.addEventListener('blur', function () { sembunyikan(); });
  }

  function barisTip(token, nama, nilai) {
    var row = document.createElement('div');
    row.className = 'paketd-tip-row';
    var dot = document.createElement('span');
    dot.className = 'paketd-tip-dot';
    dot.style.background = 'var(' + token + ')';
    var lbl = document.createElement('span');
    lbl.className = 'paketd-tip-label';
    lbl.textContent = nama;
    var val = document.createElement('span');
    val.className = 'paketd-tip-val';
    val.textContent = String(nilai);
    row.appendChild(dot);
    row.appendChild(lbl);
    row.appendChild(val);
    return row;
  }

  /* Hitung koordinat x,y untuk tiap titik (dipakai untuk menempatkan titik aktif). */
  function jalurTitik(serie, svg) {
    var vb = svg.viewBox.baseVal;
    var N = vb.width || 1000, H = vb.height || 250;
    var padAtas = 12, padBawah = 8;
    var plotH = H - padAtas - padBawah;
    var n = serie.length;
    // Skala Y sama dengan server: "ramah" dari maks total.
    var maks = 0;
    serie.forEach(function (s) { maks = Math.max(maks, s.total); });
    var yMaks = skalaRamah(maks);
    var dasar = padAtas + plotH;
    var out = [];
    serie.forEach(function (s, i) {
      var x = n > 1 ? (i / (n - 1)) * N : N / 2;
      out.push({
        x: x,
        yHadir: padAtas + plotH - (s.hadir / yMaks) * plotH,
        yTotal: padAtas + plotH - (s.total / yMaks) * plotH
      });
    });
    return out;
  }

  function posisiTitik(jalur, d, svg) {
    var vb = svg.viewBox.baseVal;
    // cari titik dgn x persen terdekat.
    var w = vb.width || 1000;
    var target = (d.x / 100) * w;
    var best = jalur[0], bd = Infinity;
    jalur.forEach(function (p) { var dd = Math.abs(p.x - target); if (dd < bd) { bd = dd; best = p; } });
    return best;
  }

  function skalaRamah(maks) {
    if (maks <= 0) { return 5; }
    var daftar = [5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000];
    for (var i = 0; i < daftar.length; i++) { if (maks <= daftar[i]) { return daftar[i]; } }
    return Math.ceil(maks / 5000) * 5000;
  }

  var BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  function tanggalPanjang(ymd) {
    var p = String(ymd).split('-');
    if (p.length !== 3) { return String(ymd); }
    var d = parseInt(p[2], 10), m = parseInt(p[1], 10);
    return d + ' ' + (BULAN[m - 1] || '') + ' ' + p[0];
  }

  function init() {
    document.querySelectorAll('.paketd-chart-card').forEach(initCard);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
