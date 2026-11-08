<?php
/**
 * Grafik dashboard (F12 revisi) — helper murni + render HTML/SVG tanpa library.
 * Tidak memakai React/Tailwind/recharts/Chart.js/CDN baru; semua dari PHP + SVG + vanilla JS.
 * Semua teks dari DB di-escape; angka dibulatkan agar SVG well-formed.
 */

/** Nama bulan singkat Bahasa Indonesia (1-12). */
function nama_bulan_singkat(int $bulan): string
{
    $peta = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
             7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    return $peta[$bulan] ?? '';
}

/**
 * Isi hari tanpa data dengan 0, urut kronologis.
 * $baris: list ['d'=>string 'Y-m-d', 'status_hadir'=>string, 'c'=>int].
 * $mulai: 'Y-m-d' hari pertama. $jumlahHari: banyak hari (termasuk $mulai).
 * Mengembalikan list ['tanggal'=>'Y-m-d', 'hadir'=>int, 'belum'=>int].
 */
function serie_harian(array $baris, string $mulai, int $jumlahHari): array
{
    $jumlahHari = max(1, $jumlahHari);
    // Peta tanggal -> ['hadir'=>, 'belum'=>].
    $peta = [];
    foreach ($baris as $b) {
        $d = (string) ($b['d'] ?? '');
        if ($d === '') {
            continue;
        }
        if (!isset($peta[$d])) {
            $peta[$d] = ['hadir' => 0, 'belum' => 0];
        }
        $c = (int) ($b['c'] ?? 0);
        if (($b['status_hadir'] ?? '') === 'Hadir') {
            $peta[$d]['hadir'] += $c;
        } else {
            $peta[$d]['belum'] += $c;
        }
    }

    $ts = strtotime($mulai . ' 00:00:00');
    if ($ts === false) {
        $ts = strtotime('today 00:00:00');
    }
    $out = [];
    for ($i = 0; $i < $jumlahHari; $i++) {
        $hari = date('Y-m-d', $ts + $i * 86400);
        $out[] = [
            'tanggal' => $hari,
            'hadir' => $peta[$hari]['hadir'] ?? 0,
            'belum' => $peta[$hari]['belum'] ?? 0,
        ];
    }
    return $out;
}

/**
 * Skala Y "ramah": angka bulat yang enak dibaca dan >= nilai maksimum, tak pernah 0.
 * Contoh: 0->5, 1->5, 7->10, 10->10, 101->200.
 */
function skala_y_ramah(int $maks): int
{
    if ($maks <= 0) {
        return 5;
    }
    $langkah = [5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000];
    foreach ($langkah as $l) {
        if ($maks <= $l) {
            return $l;
        }
    }
    // Di atas daftar: bulatkan ke kelipatan 5000 ke atas.
    return (int) (ceil($maks / 5000) * 5000);
}

/**
 * Pilih indeks titik untuk label sumbu X (rata, selalu termasuk titik pertama & terakhir).
 * $jumlahTitik: banyak titik data. $maksLabel: batas jumlah label.
 * Mengembalikan list indeks (int) urut naik, tanpa duplikat.
 */
function pilih_label_x(int $jumlahTitik, int $maksLabel): array
{
    if ($jumlahTitik <= 0) {
        return [];
    }
    if ($jumlahTitik === 1) {
        return [0];
    }
    $maksLabel = max(2, $maksLabel);
    if ($jumlahTitik <= $maksLabel) {
        return range(0, $jumlahTitik - 1);
    }
    // Jarak antar label (minimal 1) agar jumlah label <= $maksLabel.
    $langkah = (int) ceil(($jumlahTitik - 1) / ($maksLabel - 1));
    if ($langkah < 1) {
        $langkah = 1;
    }
    $idx = [];
    for ($i = 0; $i <= $jumlahTitik - 1; $i += $langkah) {
        $idx[] = $i;
    }
    // Pastikan titik terakhir selalu ada.
    $akhir = $jumlahTitik - 1;
    if (end($idx) !== $akhir) {
        // Hindari menempel pada label sebelumnya (tumpang tindih).
        if ($akhir - end($idx) >= 1) {
            $idx[] = $akhir;
        } else {
            $idx[count($idx) - 1] = $akhir;
        }
    }
    return array_values(array_unique($idx));
}

/**
 * Bangun path SVG area/garis dengan interpolasi monotone cubic (Fritsch-Carlson).
 * $titik: list [x, y] (float). y adalah koordinat SVG (makin besar = makin bawah).
 * Aman untuk 0/1 titik, data datar, dan tak pernah menghasilkan NaN/INF.
 */
function monotone_path(array $titik): string
{
    $n = count($titik);
    if ($n === 0) {
        return '';
    }
    $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    if ($n === 1) {
        return 'M ' . $fmt((float) $titik[0][0]) . ' ' . $fmt((float) $titik[0][1]);
    }

    $xs = [];
    $ys = [];
    foreach ($titik as $t) {
        $xs[] = (float) $t[0];
        $ys[] = (float) $t[1];
    }

    // Selisih antar titik; lindungi dari pembagi nol.
    $dx = [];
    $dy = [];
    $m = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $d = $xs[$i + 1] - $xs[$i];
        if ($d == 0.0) {
            $d = 1e-9;
        }
        $dx[$i] = $d;
        $dy[$i] = $ys[$i + 1] - $ys[$i];
        $m[$i] = $dy[$i] / $d;
    }

    // Tangen awal (Fritsch-Carlson): rata-rata geometris bila searah, else 0.
    $t = [];
    $t[0] = $m[0];
    for ($i = 1; $i < $n - 1; $i++) {
        if ($m[$i - 1] * $m[$i] <= 0) {
            $t[$i] = 0.0;
        } else {
            $t[$i] = ($m[$i - 1] + $m[$i]) / 2.0;
        }
    }
    $t[$n - 1] = $m[$n - 2];

    // Batasi agar monoton (tidak overshoot).
    for ($i = 0; $i < $n - 1; $i++) {
        if ($m[$i] == 0.0) {
            $t[$i] = 0.0;
            $t[$i + 1] = 0.0;
            continue;
        }
        $a = $t[$i] / $m[$i];
        $b = $t[$i + 1] / $m[$i];
        $s = $a * $a + $b * $b;
        if ($s > 9.0) {
            $tau = 3.0 / sqrt($s);
            $t[$i] = $tau * $a * $m[$i];
            $t[$i + 1] = $tau * $b * $m[$i];
        }
    }

    $path = 'M ' . $fmt($xs[0]) . ' ' . $fmt($ys[0]);
    for ($i = 0; $i < $n - 1; $i++) {
        $h = $dx[$i];
        $c1x = $xs[$i] + $h / 3.0;
        $c1y = $ys[$i] + $t[$i] * $h / 3.0;
        $c2x = $xs[$i + 1] - $h / 3.0;
        $c2y = $ys[$i + 1] - $t[$i + 1] * $h / 3.0;
        // Jaga-jaga: jangan sampai NaN/INF masuk ke output.
        foreach ([$c1x, $c1y, $c2x, $c2y, $xs[$i + 1], $ys[$i + 1]] as $v) {
            if (!is_finite($v)) {
                return $path;
            }
        }
        $path .= ' C ' . $fmt($c1x) . ' ' . $fmt($c1y) . ', ' . $fmt($c2x) . ' ' . $fmt($c2y) . ', ' . $fmt($xs[$i + 1]) . ' ' . $fmt($ys[$i + 1]);
    }
    return $path;
}

/**
 * Tanggal panjang Bahasa Indonesia: "8 Nov 2026".
 */
function tanggal_ringkas_id(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return (int) date('j', $ts) . ' ' . nama_bulan_singkat((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

/**
 * Render satu panel (satu rentang) area chart bertumpuk sebagai HTML + SVG inline.
 * $serie: list ['tanggal'=>'Y-m-d','hadir'=>int,'belum'=>int] (urut kronologis).
 * $id: id unik panel (untuk gradient & relasi aria). $panel_id: id elemen panel.
 * Mengembalikan string HTML (tanpa kartu pembungkus).
 */
function grafik_panel_html(array $serie, string $id, string $panelId, bool $aktif): string
{
    $N = 1000;
    $H = 250;
    $padAtas = 12;
    $padBawah = 8;
    $plotH = $H - $padAtas - $padBawah;

    $n = count($serie);
    $kosong = true;
    $maksTotal = 0;
    foreach ($serie as $s) {
        $tot = (int) $s['hadir'] + (int) $s['belum'];
        if ($tot > 0) {
            $kosong = false;
        }
        $maksTotal = max($maksTotal, $tot);
    }

    $hidden = $aktif ? '' : ' hidden';
    $html = '<div class="paketd-chart-panel" id="' . e($panelId) . '" data-panel' . $hidden . '>';

    if ($kosong) {
        // Keadaan kosong: pesan tengah, tinggi kartu sama, tanpa path.
        $html .= '<div class="paketd-chart-empty">' . e('Belum ada pendaftaran dalam rentang ini') . '</div>';
        $html .= '</div>';
        return $html;
    }

    $yMaks = skala_y_ramah($maksTotal);

    // Koordinat tiap titik (x rata 0..N, y dari skala).
    $titikHadir = [];
    $titikTotal = [];
    $meta = []; // data untuk tooltip: x%, tanggal, hadir, belum, total
    foreach ($serie as $i => $s) {
        $x = $n > 1 ? ($i / ($n - 1)) * $N : $N / 2;
        $hadir = (int) $s['hadir'];
        $belum = (int) $s['belum'];
        $total = $hadir + $belum;
        $yHadir = $padAtas + $plotH - ($hadir / $yMaks) * $plotH;
        $yTotal = $padAtas + $plotH - ($total / $yMaks) * $plotH;
        $titikHadir[] = [$x, $yHadir];
        $titikTotal[] = [$x, $yTotal];
        $meta[] = [
            'x' => ($n > 1 ? ($i / ($n - 1)) * 100 : 50),
            'tanggal' => (string) $s['tanggal'],
            'hadir' => $hadir,
            'belum' => $belum,
            'total' => $total,
        ];
    }

    $garisHadir = monotone_path($titikHadir);
    $garisTotal = monotone_path($titikTotal);
    // Area total (dari garis total ke dasar) dan area hadir (dari garis hadir ke dasar).
    $dasar = $padAtas + $plotH;
    $areaHadir = $garisHadir . ' L ' . $N . ' ' . $dasar . ' L 0 ' . $dasar . ' Z';
    $areaTotal = $garisTotal . ' L ' . $N . ' ' . $dasar . ' L 0 ' . $dasar . ' Z';

    $svg = '<svg class="paketd-area" viewBox="0 0 ' . $N . ' ' . $H . '" preserveAspectRatio="none" role="group" tabindex="0" xmlns="http://www.w3.org/2000/svg" '
        . 'aria-label="Grafik area pendaftar per hari. Gunakan tombol panah kiri atau kanan untuk menelusuri." '
        . 'data-grafik="1" data-panel-aktif="' . e($panelId) . '">';
    // Grid horizontal tipis (4 garis), tanpa grid vertikal, tanpa label sumbu.
    for ($g = 0; $g <= 4; $g++) {
        $y = $padAtas + ($plotH / 4) * (4 - $g);
        $svg .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . $N . '" y2="' . round($y, 1) . '" class="paketd-grid" vector-effect="non-scaling-stroke"/>';
    }
    // Gradient unik per seri.
    $svg .= '<defs>'
        . '<linearGradient id="fill-atas-' . e($id) . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="5%" stop-color="var(--chart-1)" stop-opacity="0.8"/>'
        . '<stop offset="95%" stop-color="var(--chart-1)" stop-opacity="0.1"/>'
        . '</linearGradient>'
        . '<linearGradient id="fill-bawah-' . e($id) . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="5%" stop-color="var(--chart-2)" stop-opacity="0.8"/>'
        . '<stop offset="95%" stop-color="var(--chart-2)" stop-opacity="0.1"/>'
        . '</linearGradient>'
        . '</defs>';
    // Seri atas = total (Hadir+Belum), seri bawah = Hadir (bertumpuk).
    $svg .= '<path class="paketd-area-top" d="' . $areaTotal . '" fill="url(#fill-atas-' . e($id) . ')"/>';
    $svg .= '<path class="paketd-area-top-line" d="' . $garisTotal . '" fill="none" stroke="var(--chart-1)" stroke-width="2" vector-effect="non-scaling-stroke"/>';
    $svg .= '<path class="paketd-area-bot" d="' . $areaHadir . '" fill="url(#fill-bawah-' . e($id) . ')"/>';
    $svg .= '<path class="paketd-area-bot-line" d="' . $garisHadir . '" fill="none" stroke="var(--chart-2)" stroke-width="2" vector-effect="non-scaling-stroke"/>';
    $svg .= '<g data-titik-aktif="1" hidden="hidden"><circle class="paketd-dot paketd-dot-top" r="4" fill="var(--chart-1)" stroke="var(--card-bg, #fff)" stroke-width="2" vector-effect="non-scaling-stroke"/><circle class="paketd-dot paketd-dot-bot" r="4" fill="var(--chart-2)" stroke="var(--card-bg, #fff)" stroke-width="2" vector-effect="non-scaling-stroke"/></g>';
    $svg .= '</svg>';

    // Hit area + label sumbu X (HTML span, posisi left: %).
    $html .= '<div class="paketd-chart-plot">';
    $html .= $svg;
    $html .= '<div class="paketd-hit" data-hit tabindex="-1"></div>';
    $html .= '<div class="paketd-xaxis" aria-hidden="true">';
    $idxLabel = pilih_label_x($n, 9);
    foreach ($idxLabel as $k => $i) {
        $x = $n > 1 ? ($i / ($n - 1)) * 100 : 50;
        $urut = ($k % 2 === 0) ? 'even' : 'odd';
        $lbl = ((int) date('j', strtotime($serie[$i]['tanggal']))) . ' ' . nama_bulan_singkat((int) date('n', strtotime($serie[$i]['tanggal'])));
        $html .= '<span class="paketd-xlabel paketd-xlabel--' . $urut . '" style="left:' . round($x, 3) . '%">' . e($lbl) . '</span>';
    }
    $html .= '</div>';
    // Tooltip (diisi JS).
    $html .= '<div class="paketd-tooltip" data-tooltip hidden aria-hidden="true"></div>';
    $html .= '</div>';

    // Data mentah untuk JS (JSON di atribut, bukan innerHTML).
    $html .= '<script type="application/json" data-serie>' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    // aria-live pengumuman.
    $html .= '<p class="visually-hidden" data-live aria-live="polite"></p>';
    // Tabel data (aksesibilitas).
    $html .= '<details class="paketd-chart-data"><summary>Lihat data</summary><table class="table table-sm mb-0"><thead><tr><th scope="col">Tanggal</th><th scope="col">Hadir</th><th scope="col">Belum hadir</th><th scope="col">Total</th></tr></thead><tbody>';
    foreach ($serie as $s) {
        $tot = (int) $s['hadir'] + (int) $s['belum'];
        $html .= '<tr><td>' . e(tanggal_ringkas_id((string) $s['tanggal'])) . '</td><td>' . (int) $s['hadir'] . '</td><td>' . (int) $s['belum'] . '</td><td>' . $tot . '</td></tr>';
    }
    $html .= '</tbody></table></details>';
    $html .= '</div>';
    return $html;
}

/**
 * Render seluruh kartu "Pendaftar Seminar": header + dropdown + tiga panel (90/30/7 hari) + legend + catatan.
 * $serie90/$serie30/$serie7: hasil serie_harian untuk tiap rentang.
 * $tanpaWaktu: jumlah baris created_at IS NULL (ditampilkan bila > 0).
 */
function grafik_area_html(array $serie90, array $serie30, array $serie7, int $tanpaWaktu = 0): string
{
    $html = '<div class="card card-custom card-static paketd-chart-card">';
    $html .= '<div class="card-header paketd-chart-head">'
        . '<div class="paketd-chart-headtext">'
        . '<h6 class="fw-bold mb-1">Pendaftar Seminar</h6>'
        . '<p class="text-muted small mb-0" data-desc>Menampilkan total pendaftar 3 bulan terakhir</p>'
        . '</div>'
        . '<select class="form-select paketd-chart-select" data-rentang aria-label="Pilih rentang waktu">'
        . '<option value="90d" selected>3 bulan terakhir</option>'
        . '<option value="30d">30 hari terakhir</option>'
        . '<option value="7d">7 hari terakhir</option>'
        . '</select>'
        . '</div>';
    $html .= '<div class="card-body paketd-chart-body" data-rentang-wrap>';
    $html .= grafik_panel_html($serie90, 'p90', 'panel90', true);
    $html .= grafik_panel_html($serie30, 'p30', 'panel30', false);
    $html .= grafik_panel_html($serie7, 'p7', 'panel7', false);
    $html .= '</div>';
    // Legend.
    $html .= '<div class="paketd-legend" aria-hidden="true">'
        . '<span class="paketd-legend-item"><span class="paketd-legend-box" style="background:var(--chart-1)"></span>Belum hadir</span>'
        . '<span class="paketd-legend-item"><span class="paketd-legend-box" style="background:var(--chart-2)"></span>Hadir</span>'
        . '</div>';
    if ($tanpaWaktu > 0) {
        $html .= '<p class="text-muted small mb-0 px-3 pb-3">' . (int) $tanpaWaktu . ' pendaftaran lama tanpa catatan waktu tidak dihitung</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Render daftar baris (HTML/CSS) untuk kartu ringkas: kehadiran per seminar / seminar terpopuler.
 * $baris: list ['judul'=>string, 'nilai'=>int|float, 'maks'=>int|float, 'teks'=>string, 'sub'=>?string, 'nomor'=>?int].
 * Tanpa label diputar; judul satu baris dengan ellipsis + title.
 */
function grafik_daftar_html(array $baris, string $kosong = 'Belum ada data'): string
{
    if (empty($baris)) {
        return '<p class="text-muted small mb-0">' . e($kosong) . '</p>';
    }
    $html = '<ul class="paketd-list">';
    foreach ($baris as $b) {
        $judul = (string) ($b['judul'] ?? '');
        $nilai = (float) ($b['nilai'] ?? 0);
        $maks = (float) ($b['maks'] ?? 0);
        $teks = (string) ($b['teks'] ?? (string) (int) $nilai);
        $persen = $maks > 0 ? min(100, max(0, ($nilai / $maks) * 100)) : 0;
        $html .= '<li class="paketd-list-item">';
        $html .= '<div class="paketd-list-head">';
        if (isset($b['nomor'])) {
            $html .= '<span class="paketd-list-num">' . (int) $b['nomor'] . '</span>';
        }
        $html .= '<span class="paketd-list-title" title="' . e($judul) . '">' . e($judul) . '</span>';
        $html .= '<span class="paketd-list-val">' . e($teks) . '</span>';
        $html .= '</div>';
        $html .= '<div class="paketd-list-track"><div class="paketd-list-fill" style="width:' . round($persen, 2) . '%"></div></div>';
        if (!empty($b['sub'])) {
            $html .= '<div class="paketd-list-sub text-muted small">' . e((string) $b['sub']) . '</div>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}
