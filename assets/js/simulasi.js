/**
 * ============================================================================
 * SMART ADAPTIVE TRAFFIC LIGHT — SIMULATOR PEREMPATAN 4 JALUR (BROWSER-ONLY)
 * ============================================================================
 * Catatan Arsitektur:
 * - File ini berjalan 100% di sisi browser (JavaScript murni).
 * - TIDAK terhubung ke MQTT, TIDAK menulis ke database, dan TIDAK mengirim
 *   perintah ke perangkat keras ESP32 asli.
 */

// ============================================================================
// 1. OBJEK KONFIGURASI UTAMA SIMULASI (ATURAN LOGIKA ADAPTIF PROYEK)
// ============================================================================
const KONFIG_SIMULASI = {
    jumlahJalur: 4,
    ambangCm: 10.0,            // Jarak < 10 cm dianggap "terisi"
    jendelaDetik: 5.0,         // Rasio terisi dihitung pada jendela 5 detik terakhir
    batasSepiPersen: 30.0,     // < 30% = Sepi
    batasPadatPersen: 70.0,    // 30%-70% = Sedang, > 70% = Padat
    hijauSepi: 5,              // Durasi hijau saat Sepi (5 detik)
    hijauSedang: 10,           // Durasi hijau saat Sedang (10 detik)
    hijauPadat: 15,            // Durasi hijau saat Padat (15 detik)
    hijauMin: 5,               // Durasi hijau minimum (5 detik)
    hijauMaks: 20,             // Durasi hijau maksimum (20 detik)
    kuningDetik: 2,            // Durasi kuning saat pergantian (2 detik)
    semuaMerahDetik: 1,        // Durasi semua merah saat pergantian (1 detik)
    batasKelaparanDetik: 45,   // Pengaman anti-kelaparan: tunggu >= 45 dtk wajib dilayani
    intervalTickDasarMs: 100,  // Resolusi detak simulasi dasar (0.1 detik per tick pada 1x)
};

// ============================================================================
// 2. FUNGSI PEMILIHAN JALUR BERIKUTNYA (DIPISAH AGAR MUDAH DIUBAH)
// ============================================================================
/**
 * [USULAN] Fungsi pemilihan jalur berikutnya di antara 4 jalur.
 * Aturan ini masih berstatus usulan dan ditaruh pada satu fungsi terpisah
 * agar mudah disesuaikan oleh pemilik proyek:
 * 1. Jika mode MANUAL aktif, pilih jalur prioritas yang diminta pengguna.
 * 2. Pengaman anti-kelaparan (anti-starvation): jika ada jalur selain jalur aktif
 *    yang sudah menunggu >= batasKelaparanDetik (dan belum dilayani), pilih yang
 *    paling lama menunggu.
 * 3. Pilih jalur dengan rasio terisi tertinggi (jendela 5 detik terakhir).
 * 4. Jika rasio terisi seri (mis. sama-sama 0% Sepi atau sama-sama 100% Padat),
 *    pilih jalur yang paling lama menunggu (waktuTungguDetik terbesar).
 * 5. Jika waktu tunggu juga seri, pilih urutan searah jarum jam berikutnya.
 *
 * @param {number} jalurSaatIni ID jalur yang baru saja selesai hijau (0..4)
 * @param {Array<Object>} daftarJalur Array objek state keempat jalur (indeks 1..4)
 * @param {string} modeSistem 'AUTO' atau 'MANUAL'
 * @param {number} jalurPrioritas ID jalur prioritas manual (1..4)
 * @returns {number} ID jalur berikutnya (1..4)
 */
function pilihJalurBerikutnya(jalurSaatIni, daftarJalur, modeSistem, jalurPrioritas) {
    // 1. Mode Manual: arahkan ke jalur prioritas
    if (modeSistem === 'MANUAL' && jalurPrioritas >= 1 && jalurPrioritas <= KONFIG_SIMULASI.jumlahJalur) {
        return jalurPrioritas;
    }

    // Susun kandidat jalur selain jalur yang baru saja aktif, berurutan searah jarum jam
    const kandidat = [];
    const titikAwal = (jalurSaatIni >= 1 && jalurSaatIni <= KONFIG_SIMULASI.jumlahJalur) ? jalurSaatIni : 4;
    for (let step = 1; step <= KONFIG_SIMULASI.jumlahJalur; step++) {
        const id = ((titikAwal - 1 + step) % KONFIG_SIMULASI.jumlahJalur) + 1;
        if (id !== jalurSaatIni) {
            kandidat.push(daftarJalur[id]);
        }
    }

    // 2. Pengaman Anti-Kelaparan (Anti-Starvation):
    // Cari jalur yang waktu tunggunya sudah mencapai/melebihi batasKelaparanDetik
    const kandidatKelaparan = kandidat.filter(j => j.waktuTungguDetik >= KONFIG_SIMULASI.batasKelaparanDetik);
    if (kandidatKelaparan.length > 0) {
        kandidatKelaparan.sort((a, b) => b.waktuTungguDetik - a.waktuTungguDetik);
        return kandidatKelaparan[0].id;
    }

    // 3. Pilih rasio terisi tertinggi; jika seri (selisih < 0.01%), pilih yang paling lama menunggu
    let terbaik = kandidat[0];
    for (let i = 1; i < kandidat.length; i++) {
        const uji = kandidat[i];
        const selisihRasio = uji.rasioTerisi - terbaik.rasioTerisi;

        if (selisihRasio > 0.01) {
            terbaik = uji;
        } else if (Math.abs(selisihRasio) <= 0.01) {
            // Seri pada rasio terisi -> pilih yang paling lama menunggu
            if (uji.waktuTungguDetik - terbaik.waktuTungguDetik > 0.01) {
                terbaik = uji;
            }
        }
    }

    return terbaik.id;
}

// ============================================================================
// 3. STATE SIMULASI & STRUKTUR DATA 4 JALUR
// ============================================================================
const WARNA_MOBIL = ['#3b82f6', '#f97316', '#8b5cf6', '#ec4899', '#14b8a6', '#eab308'];

function buatStateJalurAwal(id, nama) {
    return {
        id: id,
        nama: nama,
        lampu: 'merah',          // 'merah' | 'kuning' | 'hijau'
        jarakCm: 30.0,           // Jarak sensor HC-SR04 (0 - 50 cm)
        modeSensorManual: false, // true jika pengguna menggeser slider secara langsung
        antreanMobil: [],        // Daftar objek mobil visual di jalur ini
        sampel5Detik: [],        // Riwayat [{ waktuSim: number, terisi: boolean }]
        rasioTerisi: 0.0,        // 0 - 100 (%)
        kepadatan: 'Sepi',       // 'Sepi' | 'Sedang' | 'Padat'
        jumlahMobilLive: 0,      // Jumlah kendaraan nyata yang dihitung oleh worker_mqtt.php
        durasiRekomendasi: KONFIG_SIMULASI.hijauSepi,
        waktuTungguDetik: (4 - id) * 0.1, // Pembeda awal kecil agar urutan awal mulai dari Jalur 1
    };
}

const stateSimulasi = {
    berjalan: true,
    modeLiveSensor: (typeof window !== 'undefined'), // Default di browser: Real-Time mengikuti sensor ESP32
    esp32Online: false,
    kecepatan: 1,                // 1x, 2x, atau 5x
    modeSistem: 'AUTO',          // 'AUTO' | 'MANUAL'
    jalurPrioritas: 0,           // 0 = tidak ada, 1..4 = jalur prioritas manual
    waktuSimulasiDetik: 0.0,
    fase: 'SEMUA_MERAH',         // Wajib dimulai dari 'SEMUA_MERAH' ('SEMUA_MERAH' | 'HIJAU' | 'KUNING')
    faseSebelumnya: 'SEMUA_MERAH',
    jalurAktif: 0,               // Jalur yang sedang hijau/kuning (0 saat SEMUA_MERAH di awal)
    jalurTerakhirHijau: 0,       // Menyimpan jalur yang baru saja selesai hijau
    jalurTargetBerikutnya: 1,    // Jalur yang akan hijau setelah SEMUA_MERAH selesai
    durasiFaseTargetDetik: KONFIG_SIMULASI.semuaMerahDetik,
    waktuDalamFaseDetik: 0.0,
    jumlahSiklusSelesai: 0,
    jumlahPelanggaranKeselamatan: 0,
    pesanPelanggaranTerakhir: '',
    idMobilCounter: 1,
    logKejadian: [],
    jalur: {
        1: buatStateJalurAwal(1, 'Jalur 1 (Utara)'),
        2: buatStateJalurAwal(2, 'Jalur 2 (Timur)'),
        3: buatStateJalurAwal(3, 'Jalur 3 (Selatan)'),
        4: buatStateJalurAwal(4, 'Jalur 4 (Barat)'),
    },
};

let timerLoopSimulasi = null;
let timerPollingLive  = null;

// ============================================================================
// 4. LOGIKA KEPADATAN, DURASI HIJAU, & PENJAGA INVARIAN KESELAMATAN
// ============================================================================
function klasifikasikanKepadatan(rasioPersen) {
    if (rasioPersen > KONFIG_SIMULASI.batasPadatPersen) {
        return { kepadatan: 'Padat', durasi: KONFIG_SIMULASI.hijauPadat };
    }
    if (rasioPersen >= KONFIG_SIMULASI.batasSepiPersen) {
        return { kepadatan: 'Sedang', durasi: KONFIG_SIMULASI.hijauSedang };
    }
    return { kepadatan: 'Sepi', durasi: KONFIG_SIMULASI.hijauSepi };
}

function hitungDurasiHijauJalur(idJalur) {
    const j = stateSimulasi.jalur[idJalur];
    const { durasi } = klasifikasikanKepadatan(j.rasioTerisi);
    return Math.max(KONFIG_SIMULASI.hijauMin, Math.min(KONFIG_SIMULASI.hijauMaks, durasi));
}

function perbaruiJendelaSensor5Detik(dt) {
    const batasWaktuBawah = stateSimulasi.waktuSimulasiDetik - KONFIG_SIMULASI.jendelaDetik;

    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        const j = stateSimulasi.jalur[id];
        const sedangTerisi = j.jarakCm < KONFIG_SIMULASI.ambangCm;

        j.sampel5Detik.push({
            waktuSim: stateSimulasi.waktuSimulasiDetik,
            terisi: sedangTerisi,
        });

        // Buang sampel yang lebih lama dari 5 detik simulasi
        j.sampel5Detik = j.sampel5Detik.filter(s => s.waktuSim >= batasWaktuBawah);

        const total = j.sampel5Detik.length;
        const jumTerisi = j.sampel5Detik.filter(s => s.terisi).length;
        j.rasioTerisi = total > 0 ? (jumTerisi / total) * 100.0 : 0.0;

        const info = klasifikasikanKepadatan(j.rasioTerisi);
        j.kepadatan = info.kepadatan;
        j.durasiRekomendasi = Math.max(KONFIG_SIMULASI.hijauMin, Math.min(KONFIG_SIMULASI.hijauMaks, info.durasi));

        // Tambah waktu tunggu untuk jalur yang sedang merah
        if (j.lampu === 'merah') {
            j.waktuTungguDetik += dt;
        } else {
            j.waktuTungguDetik = 0.0;
        }
    }
}

/**
 * Mencatat pesan ke daftar log kejadian di halaman.
 */
function catatLogSimulasi(pesan, adalahPeringatan = false) {
    const menit = Math.floor(stateSimulasi.waktuSimulasiDetik / 60);
    const detik = (stateSimulasi.waktuSimulasiDetik % 60).toFixed(1).padStart(4, '0');
    const capWaktu = `${String(menit).padStart(2, '0')}:${detik}s`;

    stateSimulasi.logKejadian.unshift({
        waktu: capWaktu,
        pesan: pesan,
        peringatan: adalahPeringatan,
    });

    if (stateSimulasi.logKejadian.length > 40) {
        stateSimulasi.logKejadian.pop();
    }
}

/**
 * Memeriksa seluruh invarian keselamatan pada setiap langkah simulasi:
 * 1. Tidak boleh pernah ada >= 2 jalur hijau (atau tidak merah) bersamaan.
 * 2. Saat fase SEMUA_MERAH, keempat jalur wajib berwarna 'merah'.
 * 3. Urutan fase tidak boleh melompat dari HIJAU langsung ke SEMUA_MERAH tanpa KUNING,
 *    atau dari KUNING langsung ke HIJAU tanpa SEMUA_MERAH.
 */
function periksaInvarianKeselamatan() {
    let daftarHijau = [];
    let daftarAktifBukanMerah = [];

    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        const w = stateSimulasi.jalur[id].lampu;
        if (w === 'hijau') {
            daftarHijau.push(id);
        }
        if (w !== 'merah') {
            daftarAktifBukanMerah.push(id);
        }
    }

    let melanggar = false;
    let alasan = '';

    if (daftarHijau.length > 1) {
        melanggar = true;
        alasan = `Terdeteksi ${daftarHijau.length} jalur HIJAU bersamaan (Jalur ${daftarHijau.join(' & ')})!`;
    } else if (daftarAktifBukanMerah.length > 1) {
        melanggar = true;
        alasan = `Terdeteksi >1 jalur tidak merah bersamaan (Jalur ${daftarAktifBukanMerah.join(' & ')})!`;
    } else if (stateSimulasi.fase === 'SEMUA_MERAH' && daftarAktifBukanMerah.length > 0) {
        melanggar = true;
        alasan = `Fase SEMUA_MERAH tetapi Jalur ${daftarAktifBukanMerah.join(', ')} tidak berwarna merah!`;
    }

    if (melanggar) {
        stateSimulasi.jumlahPelanggaranKeselamatan++;
        stateSimulasi.pesanPelanggaranTerakhir = alasan;
        catatLogSimulasi(`[PELANGGARAN KESELAMATAN] ${alasan}`, true);

        // Pengaman darurat: kembalikan semua lampu ke merah
        for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
            stateSimulasi.jalur[id].lampu = 'merah';
        }
        stateSimulasi.fase = 'SEMUA_MERAH';
        stateSimulasi.waktuDalamFaseDetik = 0;
        return false;
    }

    return true;
}

/**
 * Mengatur warna lampu untuk keempat jalur secara aman.
 */
function terapkanWarnaLampuJalur(idJalurAktif, warnaAktif) {
    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        if (id === idJalurAktif && (warnaAktif === 'hijau' || warnaAktif === 'kuning')) {
            stateSimulasi.jalur[id].lampu = warnaAktif;
        } else {
            stateSimulasi.jalur[id].lampu = 'merah';
        }
    }
    periksaInvarianKeselamatan();
}

// ============================================================================
// 5. MESIN SIKLUS TRANSISI LAMPU (HIJAU -> KUNING -> SEMUA MERAH -> HIJAU)
// ============================================================================
function evaluasiMesinSiklus() {
    const waktuFase = stateSimulasi.waktuDalamFaseDetik;

    if (stateSimulasi.fase === 'SEMUA_MERAH') {
        terapkanWarnaLampuJalur(0, 'merah');

        if (waktuFase >= stateSimulasi.durasiFaseTargetDetik) {
            // Pilih jalur berikutnya tepat saat SEMUA_MERAH berakhir
            const idBerikutnya = pilihJalurBerikutnya(
                stateSimulasi.jalurTerakhirHijau,
                stateSimulasi.jalur,
                stateSimulasi.modeSistem,
                stateSimulasi.jalurPrioritas
            );

            const durasiHijau = hitungDurasiHijauJalur(idBerikutnya);
            stateSimulasi.faseSebelumnya = 'SEMUA_MERAH';
            stateSimulasi.fase = 'HIJAU';
            stateSimulasi.jalurAktif = idBerikutnya;
            stateSimulasi.durasiFaseTargetDetik = durasiHijau;
            stateSimulasi.waktuDalamFaseDetik = 0.0;
            stateSimulasi.jalur[idBerikutnya].waktuTungguDetik = 0.0;

            terapkanWarnaLampuJalur(idBerikutnya, 'hijau');

            const infoJalur = stateSimulasi.jalur[idBerikutnya];
            catatLogSimulasi(
                `Jalur ${idBerikutnya} -> HIJAU (${durasiHijau} dtk) | ${infoJalur.kepadatan} (${infoJalur.rasioTerisi.toFixed(0)}%, ${infoJalur.jarakCm.toFixed(1)} cm)`
            );
        }
    } else if (stateSimulasi.fase === 'HIJAU') {
        const idAktif = stateSimulasi.jalurAktif;
        const jalurSkr = stateSimulasi.jalur[idAktif];
        let pindahKeKuning = false;
        let alasanTransisi = '';

        // 1. Jika Mode MANUAL meminta jalur prioritas yang berbeda dari jalur aktif saat ini:
        //    Tetap hormati urutan keselamatan: masuk ke KUNING -> SEMUA_MERAH -> Jalur Prioritas HIJAU
        if (
            stateSimulasi.modeSistem === 'MANUAL' &&
            stateSimulasi.jalurPrioritas >= 1 &&
            stateSimulasi.jalurPrioritas <= KONFIG_SIMULASI.jumlahJalur &&
            stateSimulasi.jalurPrioritas !== idAktif
        ) {
            pindahKeKuning = true;
            alasanTransisi = `Override Manual ke Jalur ${stateSimulasi.jalurPrioritas}`;
        }
        // 2. Mode AUTO:
        else if (stateSimulasi.modeSistem === 'AUTO') {
            // Aturan A: Durasi hijau normal selesai
            if (waktuFase >= stateSimulasi.durasiFaseTargetDetik) {
                pindahKeKuning = true;
                alasanTransisi = `Durasi hijau ${stateSimulasi.durasiFaseTargetDetik} dtk selesai`;
            }
            // Aturan B (Potong-Cepat): Jalur aktif kosong (>= ambang 10 cm) setelah durasi minimum (5 dtk)
            //          DAN ada jalur lain yang sedang terisi (< ambang 10 cm)
            else if (waktuFase >= KONFIG_SIMULASI.hijauMin && jalurSkr.jarakCm >= KONFIG_SIMULASI.ambangCm) {
                let adaJalurLainTerisi = false;
                for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
                    if (id !== idAktif && stateSimulasi.jalur[id].jarakCm < KONFIG_SIMULASI.ambangCm) {
                        adaJalurLainTerisi = true;
                        break;
                    }
                }
                if (adaJalurLainTerisi) {
                    pindahKeKuning = true;
                    alasanTransisi = `Jalur ${idAktif} kosong setelah min ${KONFIG_SIMULASI.hijauMin} dtk & jalur lain mengantre`;
                }
            }
        }

        if (pindahKeKuning) {
            stateSimulasi.faseSebelumnya = 'HIJAU';
            stateSimulasi.fase = 'KUNING';
            stateSimulasi.durasiFaseTargetDetik = KONFIG_SIMULASI.kuningDetik;
            stateSimulasi.waktuDalamFaseDetik = 0.0;
            terapkanWarnaLampuJalur(idAktif, 'kuning');
            catatLogSimulasi(`Jalur ${idAktif} -> KUNING (${KONFIG_SIMULASI.kuningDetik} dtk) | ${alasanTransisi}`);
        }
    } else if (stateSimulasi.fase === 'KUNING') {
        const idAktif = stateSimulasi.jalurAktif;

        if (waktuFase >= stateSimulasi.durasiFaseTargetDetik) {
            stateSimulasi.faseSebelumnya = 'KUNING';
            stateSimulasi.fase = 'SEMUA_MERAH';
            stateSimulasi.jalurTerakhirHijau = idAktif;
            stateSimulasi.jalurAktif = 0;
            stateSimulasi.durasiFaseTargetDetik = KONFIG_SIMULASI.semuaMerahDetik;
            stateSimulasi.waktuDalamFaseDetik = 0.0;
            stateSimulasi.jumlahSiklusSelesai++;

            terapkanWarnaLampuJalur(0, 'merah');
            catatLogSimulasi(`SEMUA MERAH (${KONFIG_SIMULASI.semuaMerahDetik} dtk) — Siklus #${stateSimulasi.jumlahSiklusSelesai}`);
        }
    }
}

// ============================================================================
// 6. SIMULASI ANTREAN MOBIL & SINKRONISASI SENSOR HC-SR04 VIRTUAL
// ============================================================================
function tambahSatuMobilVisual(idJalur) {
    const j = stateSimulasi.jalur[idJalur];
    if (j.antreanMobil.length >= 6) return;

    const urutan = j.antreanMobil.length;
    // Posisi berhenti di depan garis henti: mobil ke-0 di pos 0, mobil ke-1 di belakangnya, dst.
    const jarakAntarMobil = 42;
    const posAwal = -(urutan * jarakAntarMobil) - 60;
    const warna = WARNA_MOBIL[(stateSimulasi.idMobilCounter++) % WARNA_MOBIL.length];

    j.antreanMobil.push({
        pos: posAwal,     // pos = 0 tepat di garis henti, pos > 0 melewati perempatan
        warna: warna,
        lewatGaris: false,
    });
}

function sinkronkanJarakDariAntrean(idJalur) {
    const j = stateSimulasi.jalur[idJalur];
    if (j.modeSensorManual) return;

    const jumlahMengantre = j.antreanMobil.filter(m => !m.lewatGaris).length;
    if (jumlahMengantre <= 0) {
        j.jarakCm = 30.0;
    } else {
        // Semakin banyak mobil mengantre, semakin dekat jarak di bawah ambang 10 cm (mis. 8.0 cm .. 3.0 cm)
        j.jarakCm = Math.max(2.5, 9.0 - (jumlahMengantre * 1.2));
    }
}

function perbaruiPergerakanMobil(dt) {
    const kecepatanMajuPxPerDetik = 115.0;
    const jarakAmanMobilPx = 42.0;

    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        const j = stateSimulasi.jalur[id];
        const bolehJalan = (j.lampu === 'hijau');

        let indeksAntre = 0;
        for (let i = 0; i < j.antreanMobil.length; i++) {
            const mobil = j.antreanMobil[i];

            if (bolehJalan || mobil.lewatGaris) {
                // Mobil pertama atau mobil yang sudah melewati garis henti terus melaju
                if (i === 0 || mobil.lewatGaris) {
                    mobil.pos += kecepatanMajuPxPerDetik * dt;
                } else {
                    const mobilDepan = j.antreanMobil[i - 1];
                    const batasPos = mobilDepan.pos - jarakAmanMobilPx;
                    mobil.pos = Math.min(batasPos, mobil.pos + kecepatanMajuPxPerDetik * dt);
                }
                if (mobil.pos > 8) {
                    mobil.lewatGaris = true;
                }
            } else {
                // Lampu merah/kuning: mobil berhenti berbaris rapi sebelum garis henti (pos <= 0)
                const targetBerhenti = -(indeksAntre * jarakAmanMobilPx);
                if (mobil.pos < targetBerhenti) {
                    mobil.pos = Math.min(targetBerhenti, mobil.pos + kecepatanMajuPxPerDetik * dt);
                }
                indeksAntre++;
            }
        }

        // Hapus mobil yang sudah keluar dari ujung kanvas perempatan (pos > 340 px)
        const jumlahSebelum = j.antreanMobil.length;
        j.antreanMobil = j.antreanMobil.filter(m => m.pos <= 340);

        // Jika pengguna menyetel slider jarak < 10 cm secara manual, jaga agar mobil visual tetap muncul mengalir
        if (j.modeSensorManual && j.jarakCm < KONFIG_SIMULASI.ambangCm) {
            const belumLewat = j.antreanMobil.filter(m => !m.lewatGaris).length;
            const targetMinimalMobil = j.jarakCm <= 5.0 ? 4 : 2;
            if (belumLewat < targetMinimalMobil) {
                tambahSatuMobilVisual(id);
            }
        } else if (!j.modeSensorManual && j.antreanMobil.length !== jumlahSebelum) {
            sinkronkanJarakDariAntrean(id);
        }
    }
}

// ============================================================================
// 7. DETAK SIMULASI UTAMA (STEP) & RENDER DOM
// ============================================================================
function satuLangkahSimulasi(dtDetik) {
    stateSimulasi.waktuSimulasiDetik += dtDetik;
    stateSimulasi.waktuDalamFaseDetik += dtDetik;

    perbaruiPergerakanMobil(dtDetik);

    // Jika sedang mode LIVE Sensor ESP32, lampu & kepadatan disinkronkan dari api_live_state.php
    if (stateSimulasi.modeLiveSensor) {
        for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
            const j = stateSimulasi.jalur[id];
            if (j.lampu === 'merah') {
                j.waktuTungguDetik += dtDetik;
            } else {
                j.waktuTungguDetik = 0.0;
            }
        }
        periksaInvarianKeselamatan();
        return;
    }

    perbaruiJendelaSensor5Detik(dtDetik);
    evaluasiMesinSiklus();
    periksaInvarianKeselamatan();
}

function renderTampilanSimulasi() {
    if (typeof document === 'undefined') return;

    // 1. Banner Peringatan Keselamatan
    const alertBanner = document.getElementById('safetyAlertBanner');
    const alertText = document.getElementById('safetyAlertText');
    const safetyBadge = document.getElementById('safetyStatusBadge');

    if (stateSimulasi.jumlahPelanggaranKeselamatan > 0) {
        if (alertBanner) alertBanner.classList.add('visible');
        if (alertText) {
            alertText.textContent = `PERINGATAN KESELAMATAN (${stateSimulasi.jumlahPelanggaranKeselamatan}x): ${stateSimulasi.pesanPelanggaranTerakhir}`;
        }
        if (safetyBadge) {
            safetyBadge.className = 'pill-badge badge-padat';
            safetyBadge.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${stateSimulasi.jumlahPelanggaranKeselamatan} Pelanggaran`;
        }
    } else {
        if (alertBanner) alertBanner.classList.remove('visible');
        if (safetyBadge) {
            safetyBadge.className = 'pill-badge badge-auto';
            safetyBadge.innerHTML = `<i class="bi bi-shield-check"></i> Invarian Aman (0 Pelanggaran)`;
        }
    }

    // 2. KPI Siklus Aktif & Badge Tengah Perempatan
    const sisaDetik = Math.max(0.0, stateSimulasi.durasiFaseTargetDetik - stateSimulasi.waktuDalamFaseDetik);
    const elActiveLane = document.getElementById('kpiActiveLane');
    const elPhaseName = document.getElementById('kpiPhaseName');
    const elRemaining = document.getElementById('kpiRemainingSec');
    const elCycleCount = document.getElementById('kpiCycleCount');
    const elCenterBadge = document.getElementById('centerStageBadge');

    const teksJalurAktif = stateSimulasi.jalurAktif > 0 ? `Jalur ${stateSimulasi.jalurAktif}` : 'Semua Merah';
    const teksFase = stateSimulasi.fase.replace('_', ' ');

    if (elActiveLane) elActiveLane.textContent = teksJalurAktif;
    if (elPhaseName) elPhaseName.textContent = teksFase;
    if (elRemaining) elRemaining.textContent = sisaDetik.toFixed(1);
    if (elCycleCount) elCycleCount.textContent = String(stateSimulasi.jumlahSiklusSelesai);
    if (elCenterBadge) {
        elCenterBadge.innerHTML = `${teksJalurAktif}<br><span style="color:#fbbf24;">${teksFase} (${sisaDetik.toFixed(1)}s)</span>`;
    }

    // 3. Perbarui 4 Kartu Jalur & Lampu di Kanvas Perempatan
    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        const j = stateSimulasi.jalur[id];
        const terisi = j.jarakCm < KONFIG_SIMULASI.ambangCm;

        // Bohlam di perempatan
        ['merah', 'kuning', 'hijau'].forEach(warna => {
            const bulb = document.getElementById(`bulb-${id}-${warna}`);
            if (bulb) {
                bulb.classList.toggle('active', j.lampu === warna);
            }
        });

        // Zona sensor ultrasonik di jalan
        const zone = document.getElementById(`sensorZone${id}`);
        if (zone) {
            zone.classList.toggle('occupied', terisi);
        }

        // Sub-label di sudut perempatan
        const stageSub = document.getElementById(`stageSub${id}`);
        const infoAntreanTeks = stateSimulasi.modeLiveSensor
            ? `${j.jumlahMobilLive} Mobil`
            : `${j.rasioTerisi.toFixed(0)}%`;
        if (stageSub) {
            stageSub.textContent = `${j.jarakCm.toFixed(1)} cm • ${j.kepadatan} (${infoAntreanTeks})`;
        }

        // Kartu jalur di panel kanan
        const card = document.getElementById(`laneCard-${id}`);
        if (card) {
            card.classList.toggle('active-green', j.lampu === 'hijau');
            card.classList.toggle('active-yellow', j.lampu === 'kuning');
        }

        const densityBadge = document.getElementById(`laneDensityBadge-${id}`);
        if (densityBadge) {
            let cls = 'pill-badge badge-auto';
            if (j.kepadatan === 'Padat') cls = 'pill-badge badge-padat';
            else if (j.kepadatan === 'Sedang') cls = 'pill-badge badge-manual';
            densityBadge.className = cls;
            densityBadge.textContent = `${j.kepadatan} (${infoAntreanTeks})`;
        }

        const lightText = document.getElementById(`laneLightText-${id}`);
        if (lightText) {
            lightText.textContent = j.lampu.charAt(0).toUpperCase() + j.lampu.slice(1);
            lightText.style.color = j.lampu === 'hijau' ? '#059669' : (j.lampu === 'kuning' ? '#d97706' : '#dc2626');
        }

        const queueCount = document.getElementById(`laneQueueCount-${id}`);
        if (queueCount) {
            const mengantre = stateSimulasi.modeLiveSensor
                ? j.jumlahMobilLive
                : j.antreanMobil.filter(m => !m.lewatGaris).length;
            queueCount.textContent = String(mengantre);
        }

        const waitSec = document.getElementById(`laneWaitSec-${id}`);
        if (waitSec) {
            waitSec.textContent = j.waktuTungguDetik.toFixed(0);
        }

        const distLabel = document.getElementById(`laneDistLabel-${id}`);
        if (distLabel) {
            distLabel.textContent = `${j.jarakCm.toFixed(1)} cm`;
        }

        const occTag = document.getElementById(`laneOccupiedTag-${id}`);
        if (occTag) {
            const ambangTampil = KONFIG_SIMULASI.ambangCm;
            occTag.textContent = terisi ? `Terisi (<${ambangTampil} cm)` : `Kosong (≥${ambangTampil} cm)`;
            occTag.style.color = terisi ? '#d97706' : '#6b6a67';
        }

        const slider = document.getElementById(`laneSlider-${id}`);
        if (slider && document.activeElement !== slider) {
            slider.value = String(Math.min(50, j.jarakCm));
        }

        // Tombol Kendali Manual Langsung per Kartu Jalur
        const btnManual = document.getElementById(`btnManualLane-${id}`);
        const textManual = document.getElementById(`textManualLane-${id}`);
        const adalahPrioritasManual = (stateSimulasi.modeSistem === 'MANUAL' && stateSimulasi.jalurPrioritas === id);
        if (btnManual) {
            btnManual.classList.toggle('active-priority', adalahPrioritasManual);
        }
        if (textManual) {
            textManual.textContent = adalahPrioritasManual
                ? `Prioritas Manual Aktif (Klik -> AUTO)`
                : `Hijaukan Manual Jalur ${id}`;
        }
    }

    // Tombol Cepat Kembali ke AUTO di Bar Atas
    const btnQuickAuto = document.getElementById('btnQuickAuto');
    if (btnQuickAuto) {
        btnQuickAuto.style.display = (stateSimulasi.modeSistem === 'MANUAL') ? 'inline-flex' : 'none';
    }

    // 4. Render Mobil di Kanvas Perempatan (490 x 490)
    const carsLayer = document.getElementById('carsLayer');
    if (carsLayer) {
        let htmlMobil = '';
        for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
            const j = stateSimulasi.jalur[id];
            for (const m of j.antreanMobil) {
                let x = 0;
                let y = 0;
                let orientasi = 'v-car';

                if (id === 1) {
                    // Jalur 1 (Utara -> bergerak turun di lajur kiri dari arah Utara)
                    orientasi = 'v-car';
                    x = 272;
                    y = 128 + m.pos;
                } else if (id === 3) {
                    // Jalur 3 (Selatan -> bergerak naik di lajur kiri dari arah Selatan)
                    orientasi = 'v-car';
                    x = 196;
                    y = 328 - m.pos;
                } else if (id === 2) {
                    // Jalur 2 (Timur -> bergerak ke kiri di lajur kiri dari arah Timur)
                    orientasi = 'h-car';
                    x = 328 - m.pos;
                    y = 272;
                } else if (id === 4) {
                    // Jalur 4 (Barat -> bergerak ke kanan di lajur kiri dari arah Barat)
                    orientasi = 'h-car';
                    x = 128 + m.pos;
                    y = 196;
                }

                htmlMobil += `<div class="sim-car ${orientasi}" style="left:${x.toFixed(1)}px; top:${y.toFixed(1)}px; background:${m.warna};"></div>`;
            }
        }
        carsLayer.innerHTML = htmlMobil;
    }

    // 5. Render Daftar Log Kejadian
    const logContainer = document.getElementById('simLogList');
    if (logContainer) {
        logContainer.innerHTML = stateSimulasi.logKejadian
            .slice(0, 15)
            .map(item => {
                const styleBaris = item.peringatan ? 'border-color:#ef4444; background:#fef2f2;' : '';
                return `<div class="sim-log-item" style="${styleBaris}">
                    <span class="sim-log-time">[${item.waktu}]</span>
                    <span class="sim-log-msg">${item.pesan}</span>
                </div>`;
            })
            .join('');
    }
}

// ============================================================================
// 8. KONTROL INTERAKTIF PENGGUNA (MULAI/JEDA/RESET, KECEPATAN, SENSOR, MODE)
// ============================================================================
function mulaiLoopInterval() {
    if (timerLoopSimulasi) clearInterval(timerLoopSimulasi);
    timerLoopSimulasi = setInterval(() => {
        if (!stateSimulasi.berjalan) return;
        const dtDasar = KONFIG_SIMULASI.intervalTickDasarMs / 1000.0;
        for (let step = 0; step < stateSimulasi.kecepatan; step++) {
            satuLangkahSimulasi(dtDasar);
        }
        renderTampilanSimulasi();
    }, KONFIG_SIMULASI.intervalTickDasarMs);
}

function toggleSimulasi() {
    stateSimulasi.berjalan = !stateSimulasi.berjalan;
    const btn = document.getElementById('btnToggleSim');
    const icon = document.getElementById('iconToggleSim');
    const text = document.getElementById('textToggleSim');

    if (stateSimulasi.berjalan) {
        if (icon) icon.className = 'bi bi-pause-fill';
        if (text) text.textContent = 'Jeda';
        if (btn) btn.classList.add('primary');
        catatLogSimulasi('Simulasi dilanjutkan.');
    } else {
        if (icon) icon.className = 'bi bi-play-fill';
        if (text) text.textContent = 'Mulai';
        if (btn) btn.classList.remove('primary');
        catatLogSimulasi('Simulasi dijeda.');
    }
    renderTampilanSimulasi();
}

function setKecepatanSimulasi(faktor) {
    if (![1, 2, 5].includes(faktor)) return;
    stateSimulasi.kecepatan = faktor;

    [1, 2, 5].forEach(f => {
        const b = document.getElementById(`btnSpeed${f}`);
        if (b) b.classList.toggle('active-speed', f === faktor);
    });

    catatLogSimulasi(`Kecepatan simulasi diubah ke ${faktor}x.`);
    renderTampilanSimulasi();
}

function setModeLiveSensor(aktif) {
    stateSimulasi.modeLiveSensor = Boolean(aktif);

    if (typeof document !== 'undefined') {
        const btnLive = document.getElementById('btnLiveEsp32');
        const textLive = document.getElementById('textLiveEsp32');
        const btnVirt = document.getElementById('btnVirtualSim');
        const headerText = document.getElementById('headerModeText');
        const headerDot = document.getElementById('headerModeDot');

        if (stateSimulasi.modeLiveSensor) {
            if (btnLive) btnLive.classList.add('primary');
            if (textLive) textLive.textContent = 'LIVE Sensor ESP32 (Aktif)';
            if (btnVirt) btnVirt.classList.remove('primary');
            if (headerText) headerText.textContent = 'Mode: LIVE Sensor ESP32';
            if (headerDot) headerDot.style.background = '#10b981';
            catatLogSimulasi('Beralih ke Mode LIVE Sensor ESP32 (sinkronisasi real-time dengan hardware & Worker MQTT).');
            sinkronkanDataLiveEsp32();
        } else {
            KONFIG_SIMULASI.ambangCm = 10.0;
            const elAmbang = document.getElementById('descAmbangCm');
            if (elAmbang) elAmbang.textContent = '10';
            if (btnLive) btnLive.classList.remove('primary');
            if (textLive) textLive.textContent = 'LIVE Sensor ESP32';
            if (btnVirt) btnVirt.classList.add('primary');
            if (headerText) headerText.textContent = 'Mode: Simulator Virtual';
            if (headerDot) headerDot.style.background = '#3b82f6';
            catatLogSimulasi('Beralih ke Mode Simulator Virtual (gunakan slider jarak atau tombol preset).');
        }
    }
    renderTampilanSimulasi();
}

let waktuOverrideManualLokal = 0;
let sedangFetchLive = false;

async function sinkronkanDataLiveEsp32() {
    if (!stateSimulasi.modeLiveSensor || typeof fetch === 'undefined' || sedangFetchLive) return;
    sedangFetchLive = true;

    try {
        const res = await fetch('api_live_state.php?t=' + Date.now(), { cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || !data.ok || !Array.isArray(data.lanes)) return;

        stateSimulasi.esp32Online = Boolean(data.esp32_online);
        if (typeof data.ambang_cm === 'number' && data.ambang_cm > 0) {
            KONFIG_SIMULASI.ambangCm = data.ambang_cm;
            const elAmbang = typeof document !== 'undefined' ? document.getElementById('descAmbangCm') : null;
            if (elAmbang) elAmbang.textContent = String(data.ambang_cm);
        }

        // Sinkronkan mode kerja & prioritas (beri jeda 1.5 detik setelah klik manual lokal agar request POST selesai dulu)
        if ((Date.now() - waktuOverrideManualLokal) > 1500) {
            const modeDb = (data.mode === 'MANUAL') ? 'MANUAL' : 'AUTO';
            const prioDb = parseInt(data.prioritas || 0, 10);
            stateSimulasi.modeSistem = modeDb;
            stateSimulasi.jalurPrioritas = prioDb;

            if (typeof document !== 'undefined') {
                const selectMode = document.getElementById('selectModeSim');
                if (selectMode && document.activeElement !== selectMode) {
                    selectMode.value = (modeDb === 'MANUAL' && prioDb >= 1 && prioDb <= 4)
                        ? `MANUAL_${prioDb}`
                        : 'AUTO';
                }
            }
        }

        if (typeof document !== 'undefined') {
            const headerText = document.getElementById('headerModeText');
            const headerDot = document.getElementById('headerModeDot');
            if (headerText) {
                headerText.textContent = stateSimulasi.esp32Online
                    ? 'LIVE ESP32: ONLINE'
                    : 'LIVE Database (ESP32 Offline)';
            }
            if (headerDot) {
                headerDot.style.background = stateSimulasi.esp32Online ? '#10b981' : '#f59e0b';
            }
        }

        // Deteksi perubahan fase atau jalur aktif untuk log dan timer fase
        const faseBaru = (data.phase_state === 'KUNING' || data.phase_state === 'SEMUA_MERAH')
            ? data.phase_state
            : 'HIJAU';
        const idAktifBaru = (faseBaru === 'SEMUA_MERAH') ? 0 : (parseInt(data.active_lane || 1, 10));

        let durasiHijauAktif = KONFIG_SIMULASI.hijauSepi;
        for (const l of data.lanes) {
            const id = parseInt(l.id, 10);
            if (!stateSimulasi.jalur[id]) continue;
            const j = stateSimulasi.jalur[id];

            j.jarakCm = parseFloat(l.jarak_cm ?? 30.0);
            j.lampu = (['merah', 'kuning', 'hijau'].includes(l.lampu_aktif)) ? l.lampu_aktif : 'merah';
            j.rasioTerisi = parseFloat(l.rasio_terisi ?? 0.0);
            j.kepadatan = l.kepadatan || 'Sepi';
            j.jumlahMobilLive = parseInt(l.jumlah_kendaraan ?? 0, 10);
            j.durasiRekomendasi = parseInt(l.durasi_hijau ?? 5, 10);

            if (id === idAktifBaru) {
                durasiHijauAktif = j.durasiRekomendasi;
            }

            // Sinkronkan jumlah mobil animasi visual di jalan dengan jumlah kendaraan nyata dari sensor
            const terisiSekarang = j.jarakCm < KONFIG_SIMULASI.ambangCm;
            const targetMobilVisual = Math.min(6, Math.max(j.jumlahMobilLive, terisiSekarang ? 1 : 0));
            const mobilMengantre = j.antreanMobil.filter(m => !m.lewatGaris).length;

            if (mobilMengantre < targetMobilVisual) {
                for (let k = mobilMengantre; k < targetMobilVisual; k++) {
                    tambahSatuMobilVisual(id);
                }
            } else if (mobilMengantre > targetMobilVisual && j.lampu !== 'hijau') {
                // Kurangi kelebihan mobil visual saat counter di-reset
                let selisih = mobilMengantre - targetMobilVisual;
                while (selisih > 0) {
                    const idx = j.antreanMobil.findLastIndex(m => !m.lewatGaris);
                    if (idx >= 0) j.antreanMobil.splice(idx, 1);
                    selisih--;
                }
            }
        }

        if (typeof data.phase_target_s === 'number' && data.phase_target_s > 0) {
            stateSimulasi.durasiFaseTargetDetik = data.phase_target_s;
        }
        if (typeof data.phase_remaining_s === 'number' && data.phase_remaining_s >= 0) {
            stateSimulasi.waktuDalamFaseDetik = Math.max(0.0, stateSimulasi.durasiFaseTargetDetik - data.phase_remaining_s);
        }

        if (faseBaru !== stateSimulasi.fase || idAktifBaru !== stateSimulasi.jalurAktif) {
            if (stateSimulasi.fase === 'KUNING' && faseBaru === 'SEMUA_MERAH') {
                stateSimulasi.jumlahSiklusSelesai++;
            }
            stateSimulasi.faseSebelumnya = stateSimulasi.fase;
            stateSimulasi.fase = faseBaru;
            stateSimulasi.jalurAktif = idAktifBaru;

            if (faseBaru === 'HIJAU') {
                const jAktif = stateSimulasi.jalur[idAktifBaru];
                if (jAktif) {
                    catatLogSimulasi(
                        `[LIVE] Jalur ${idAktifBaru} -> HIJAU (${stateSimulasi.durasiFaseTargetDetik} dtk) | ${jAktif.kepadatan} (${jAktif.jumlahMobilLive} Mobil, ${jAktif.jarakCm.toFixed(1)} cm)`
                    );
                }
            } else if (faseBaru === 'KUNING') {
                catatLogSimulasi(`[LIVE] Jalur ${idAktifBaru} -> KUNING (${KONFIG_SIMULASI.kuningDetik} dtk)`);
            } else {
                catatLogSimulasi(`[LIVE] SEMUA MERAH (${KONFIG_SIMULASI.semuaMerahDetik} dtk)`);
            }
        }

        periksaInvarianKeselamatan();
        renderTampilanSimulasi();
    } catch (e) {
        // Abaikan gangguan jaringan sesaat
    } finally {
        sedangFetchLive = false;
    }
}

function ubahModeSimulasi(nilaiSelect) {
    waktuOverrideManualLokal = Date.now();
    if (nilaiSelect === 'AUTO') {
        stateSimulasi.modeSistem = 'AUTO';
        stateSimulasi.jalurPrioritas = 0;
        catatLogSimulasi('Mode kerja diubah ke OTOMATIS (Adaptif Sensor 4 Jalur).');
        if (stateSimulasi.modeLiveSensor && typeof fetch !== 'undefined') {
            const body = new URLSearchParams({ id: '0', mode: 'auto' });
            fetch('update_manual.php', { method: 'POST', body }).then(() => {
                setTimeout(sinkronkanDataLiveEsp32, 300);
            });
        }
    } else if (nilaiSelect.startsWith('MANUAL_')) {
        const idPrio = parseInt(nilaiSelect.replace('MANUAL_', ''), 10);
        if (idPrio >= 1 && idPrio <= KONFIG_SIMULASI.jumlahJalur) {
            stateSimulasi.modeSistem = 'MANUAL';
            stateSimulasi.jalurPrioritas = idPrio;
            catatLogSimulasi(`Mode MANUAL aktif: Prioritas Jalur ${idPrio} menjadi Hijau (melewati transisi aman Kuning -> Semua Merah).`);
            if (stateSimulasi.modeLiveSensor && typeof fetch !== 'undefined') {
                const body = new URLSearchParams({ id: String(idPrio), mode: 'manual' });
                fetch('update_manual.php', { method: 'POST', body }).then(() => {
                    setTimeout(sinkronkanDataLiveEsp32, 300);
                });
            }
        }
    }
    if (typeof document !== 'undefined') {
        const selectMode = document.getElementById('selectModeSim');
        if (selectMode) {
            selectMode.value = (stateSimulasi.modeSistem === 'MANUAL' && stateSimulasi.jalurPrioritas >= 1)
                ? `MANUAL_${stateSimulasi.jalurPrioritas}`
                : 'AUTO';
        }
    }
    renderTampilanSimulasi();
}

/**
 * Mengatur lampu jalur tertentu menjadi Hijau secara Manual (idJalur = 1..4),
 * atau mengembalikan ke mode Otomatis jika idJalur = 0.
 */
function setManualHijauJalur(idJalur) {
    if (idJalur >= 1 && idJalur <= KONFIG_SIMULASI.jumlahJalur) {
        ubahModeSimulasi(`MANUAL_${idJalur}`);
    } else {
        ubahModeSimulasi('AUTO');
    }
}

/**
 * Toggle tombol manual pada kartu jalur:
 * - Jika jalur tersebut belum menjadi prioritas manual -> jadikan Hijau (Manual).
 * - Jika jalur tersebut sudah menjadi prioritas manual -> kembalikan ke Otomatis (AUTO).
 */
function toggleManualJalur(idJalur) {
    if (stateSimulasi.modeSistem === 'MANUAL' && stateSimulasi.jalurPrioritas === idJalur) {
        setManualHijauJalur(0);
    } else {
        setManualHijauJalur(idJalur);
    }
}

function ubahSliderJarak(idJalur, nilaiCm) {
    const j = stateSimulasi.jalur[idJalur];
    if (!j) return;

    if (stateSimulasi.modeLiveSensor) {
        setModeLiveSensor(false);
    }

    j.modeSensorManual = true;
    j.jarakCm = Math.max(0.0, Math.min(50.0, parseFloat(nilaiCm) || 0.0));

    if (j.jarakCm < KONFIG_SIMULASI.ambangCm && j.antreanMobil.filter(m => !m.lewatGaris).length === 0) {
        tambahSatuMobilVisual(idJalur);
        tambahSatuMobilVisual(idJalur);
    } else if (j.jarakCm >= KONFIG_SIMULASI.ambangCm) {
        j.antreanMobil = j.antreanMobil.filter(m => m.lewatGaris);
    }
    renderTampilanSimulasi();
}

function ubahAntreanMobil(idJalur, delta) {
    const j = stateSimulasi.jalur[idJalur];
    if (!j) return;

    // Jika sedang Mode LIVE Sensor ESP32, kirim perubahan jumlah mobil secara real-time ke Database & Worker MQTT!
    if (stateSimulasi.modeLiveSensor && typeof fetch !== 'undefined') {
        j.jumlahMobilLive = Math.max(0, (j.jumlahMobilLive || 0) + delta);
        if (j.jumlahMobilLive >= 4) {
            j.kepadatan = 'Padat';
            j.durasiRekomendasi = KONFIG_SIMULASI.hijauPadat;
        } else if (j.jumlahMobilLive >= 2) {
            j.kepadatan = 'Sedang';
            j.durasiRekomendasi = KONFIG_SIMULASI.hijauSedang;
        } else {
            j.kepadatan = 'Sepi';
            j.durasiRekomendasi = KONFIG_SIMULASI.hijauSepi;
        }

        if (delta > 0) {
            tambahSatuMobilVisual(idJalur);
        } else if (delta < 0) {
            const idxTerakhir = j.antreanMobil.findLastIndex(m => !m.lewatGaris);
            if (idxTerakhir >= 0) {
                j.antreanMobil.splice(idxTerakhir, 1);
            }
        }

        catatLogSimulasi(`[LIVE] Antrean Jalur ${idJalur} disesuaikan (${delta > 0 ? '+1' : '-1'} mobil) -> Total: ${j.jumlahMobilLive} Mobil (${j.kepadatan}).`);
        renderTampilanSimulasi();

        const body = new URLSearchParams({ id: String(idJalur), delta: String(delta) });
        fetch('api_live_state.php', { method: 'POST', body }).then(() => {
            setTimeout(sinkronkanDataLiveEsp32, 250);
        });
        return;
    }

    j.modeSensorManual = false;
    if (delta > 0) {
        tambahSatuMobilVisual(idJalur);
    } else if (delta < 0) {
        const idxTerakhir = j.antreanMobil.findLastIndex(m => !m.lewatGaris);
        if (idxTerakhir >= 0) {
            j.antreanMobil.splice(idxTerakhir, 1);
        }
    }
    sinkronkanJarakDariAntrean(idJalur);
    renderTampilanSimulasi();
}

function bersihkanLogSimulasi() {
    stateSimulasi.logKejadian = [];
    renderTampilanSimulasi();
}

function resetSimulasi() {
    stateSimulasi.waktuSimulasiDetik = 0.0;
    stateSimulasi.fase = 'SEMUA_MERAH';
    stateSimulasi.faseSebelumnya = 'SEMUA_MERAH';
    stateSimulasi.jalurAktif = 0;
    stateSimulasi.jalurTerakhirHijau = 0;
    stateSimulasi.durasiFaseTargetDetik = KONFIG_SIMULASI.semuaMerahDetik;
    stateSimulasi.waktuDalamFaseDetik = 0.0;
    stateSimulasi.jumlahSiklusSelesai = 0;
    stateSimulasi.jumlahPelanggaranKeselamatan = 0;
    stateSimulasi.pesanPelanggaranTerakhir = '';
    stateSimulasi.modeSistem = 'AUTO';
    stateSimulasi.jalurPrioritas = 0;
    stateSimulasi.logKejadian = [];

    const selectMode = typeof document !== 'undefined' ? document.getElementById('selectModeSim') : null;
    if (selectMode) selectMode.value = 'AUTO';

    for (let id = 1; id <= KONFIG_SIMULASI.jumlahJalur; id++) {
        stateSimulasi.jalur[id] = buatStateJalurAwal(id, stateSimulasi.jalur[id].nama);
    }

    if (stateSimulasi.modeLiveSensor) {
        catatLogSimulasi('Mode LIVE Sensor ESP32 aktif. Menyinkronkan data sensor & lampu real-time...');
        sinkronkanDataLiveEsp32();
    } else {
        catatLogSimulasi('Simulasi di-reset. Keadaan awal: SEMUA MERAH (1.0 dtk).');
    }
    renderTampilanSimulasi();
}

/**
 * Menerapkan 3 preset skenario cepat:
 * - 'A': Satu jalur padat (Jalur 1 = 4.0 cm), tiga jalur sepi (30.0 cm)
 * - 'B': Semua jalur padat (Jalur 1..4 = 4.0 cm)
 * - 'C': Semua jalur sepi (Jalur 1..4 = 30.0 cm)
 */
function terapkanPresetSkenario(kode) {
    if (stateSimulasi.modeLiveSensor) {
        setModeLiveSensor(false);
    }
    resetSimulasi();
    if (kode === 'A') {
        ubahSliderJarak(1, 4.0);
        ubahSliderJarak(2, 30.0);
        ubahSliderJarak(3, 30.0);
        ubahSliderJarak(4, 30.0);
        // Isi buffer awal 5 detik agar rasio langsung mencerminkan skenario
        for (let t = -4.9; t <= 0; t += 0.1) {
            stateSimulasi.jalur[1].sampel5Detik.push({ waktuSim: t, terisi: true });
            stateSimulasi.jalur[2].sampel5Detik.push({ waktuSim: t, terisi: false });
            stateSimulasi.jalur[3].sampel5Detik.push({ waktuSim: t, terisi: false });
            stateSimulasi.jalur[4].sampel5Detik.push({ waktuSim: t, terisi: false });
        }
        catatLogSimulasi('Preset (a) diterapkan: Jalur 1 Padat (4.0 cm), Jalur 2-4 Sepi (30.0 cm).');
    } else if (kode === 'B') {
        for (let id = 1; id <= 4; id++) {
            ubahSliderJarak(id, 4.0);
            for (let t = -4.9; t <= 0; t += 0.1) {
                stateSimulasi.jalur[id].sampel5Detik.push({ waktuSim: t, terisi: true });
            }
        }
        catatLogSimulasi('Preset (b) diterapkan: Keempat Jalur Padat (4.0 cm).');
    } else if (kode === 'C') {
        for (let id = 1; id <= 4; id++) {
            ubahSliderJarak(id, 30.0);
            for (let t = -4.9; t <= 0; t += 0.1) {
                stateSimulasi.jalur[id].sampel5Detik.push({ waktuSim: t, terisi: false });
            }
        }
        catatLogSimulasi('Preset (c) diterapkan: Keempat Jalur Sepi (30.0 cm).');
    }
    perbaruiJendelaSensor5Detik(0.0);
    renderTampilanSimulasi();
}

// ============================================================================
// 9. INISIALISASI SAAT HALAMAN DIMUAT & EKSPOR UNTUK PENGUJIAN OTOMATIS
// ============================================================================
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    window.addEventListener('DOMContentLoaded', () => {
        resetSimulasi();
        mulaiLoopInterval();
        if (timerPollingLive) clearInterval(timerPollingLive);
        timerPollingLive = setInterval(() => {
            if (stateSimulasi.berjalan && stateSimulasi.modeLiveSensor) {
                sinkronkanDataLiveEsp32();
            }
        }, 250);
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        KONFIG_SIMULASI,
        stateSimulasi,
        pilihJalurBerikutnya,
        satuLangkahSimulasi,
        resetSimulasi,
        terapkanPresetSkenario,
        ubahModeSimulasi,
        ubahSliderJarak,
    };
}
