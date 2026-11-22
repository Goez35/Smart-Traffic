/**
 * ============================================================================
 * SMART ADAPTIVE TRAFFIC LIGHT — FIRMWARE ESP32 (4 JALUR, I/O + MQTT + FALLBACK)
 * ============================================================================
 * Peran ESP32 pada arsitektur baru:
 * 1. Membaca 4 sensor ultrasonik HC-SR04 secara bergantian dengan filter median-3.
 * 2. Mengirimkan data jarak (cm) ke broker EMQX melalui topik `smarttraffic/{device_id}/sensor`.
 * 3. Menerima perintah lampu dari Worker PHP melalui topik `smarttraffic/{device_id}/cmd`.
 * 4. Menjaga Invarian Keselamatan Lokal:
 *    - Tidak pernah mengizinkan 2 jalur hijau bersamaan dalam kondisi apa pun.
 *    - Setiap pergantian jalur hijau WAJIB melewati transisi:
 *      Hijau -> Kuning (2 dtk) -> Semua Merah (1 dtk) -> Hijau jalur berikutnya.
 * 5. Mengirim konfirmasi warna aktual ke topik `smarttraffic/{device_id}/state`
 *    serta status `online`/`offline` (Last Will) di `smarttraffic/{device_id}/status`.
 * 6. Mode Fallback Lokal (Opsi A):
 *    - Bila koneksi Wi-Fi/MQTT putus atau tidak ada perintah `cmd` selama > 10 detik,
 *      ESP32 otomatis menjalankan siklus tetap lokal (Hijau 5s -> Kuning 2s -> Semua Merah 1s)
 *      secara bergiliran untuk Jalur 1 s/d 4 hingga koneksi cloud pulih.
 *
 * Pustaka (Library) yang dibutuhkan di Arduino IDE:
 * - PubSubClient (oleh Nick O'Leary)
 * - ArduinoJson (oleh Benoit Blanchon, versi 6.x atau 7.x)
 */
 
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>

// Jika secrets.h sudah dibuat oleh pengguna, gunakan secrets.h; jika belum, gunakan secrets.example.h
#if __has_include("secrets.h")
  #include "secrets.h"
#else
  #include "secrets.example.h"
#endif

// ============================================================================
// 1. KONFIGURASI PIN GPIO (4 JALUR: 4 SENSOR HC-SR04 & 12 LED)
// ============================================================================
// Catatan: Jalur 1 (A) dan Jalur 2 (B) mempertahankan pin asli dari file .ino awal.
// Jalur 3 (C) dan Jalur 4 (D) menggunakan pin GPIO bebas konflik & ECHO input-only (34, 35).

const int JUMLAH_JALUR = 4;

struct PinJalur {
  uint8_t id;
  uint8_t trigPin;
  uint8_t echoPin;
  uint8_t merahPin;
  uint8_t kuningPin;
  uint8_t hijauPin;
};

const PinJalur JALUR[JUMLAH_JALUR] = {
  // ID, TRIG, ECHO, MERAH, KUNING, HIJAU
  { 1,    5,   18,    19,     21,    22 }, // Jalur 1 (Simpang A - Utara)  [Pin Asli .ino]
  { 2,   13,   12,    23,      4,     2 }, // Jalur 2 (Simpang B - Timur)  [Pin Asli .ino]
  { 3,   14,   34,    25,     26,    27 }, // Jalur 3 (Simpang C - Selatan)[Konfigurasi Baru Aman]
  { 4,   32,   35,    33,     16,    17 }  // Jalur 4 (Simpang D - Barat)  [Konfigurasi Baru Aman]
};

// ============================================================================
// 2. KONSTANTA WAKTU & INVARIAN KESELAMATAN
// ============================================================================
const unsigned long INTERVAL_BACA_PER_SENSOR_MS = 120;   // Jeda antar-sensor agar gelombang tidak tabrakan
const unsigned long INTERVAL_PUBLISH_SENSOR_MS  = 500;   // Kirim paket JSON sensor tiap 500 ms
const unsigned long INTERVAL_RECONNECT_MQTT_MS  = 3000;  // Coba sambung ulang MQTT tiap 3 detik
const unsigned long FALLBACK_TIMEOUT_MS         = 10000; // 10 detik tanpa perintah cloud -> Fallback Lokal

const unsigned long DURASI_KUNING_MS            = 2000;  // Wajib 2 detik kuning sebelum merah
const unsigned long DURASI_SEMUA_MERAH_MS       = 1000;  // Wajib 1 detik semua merah sebelum jalur lain hijau
const unsigned long DURASI_HIJAU_FALLBACK_MS    = 5000;  // Durasi hijau per jalur saat mode Fallback Lokal (Opsi A)

// ============================================================================
// 3. STATE MESIN LAMPU LOKAL (PENJAGA KESELAMATAN & TRANSISI)
// ============================================================================
enum WarnaLampu {
  WARNA_MERAH = 0,
  WARNA_KUNING = 1,
  WARNA_HIJAU = 2
};

enum FaseSiklus {
  FASE_SEMUA_MERAH = 0,
  FASE_HIJAU       = 1,
  FASE_KUNING      = 2
};

WarnaLampu statusLampuAktual[JUMLAH_JALUR] = { WARNA_MERAH, WARNA_MERAH, WARNA_MERAH, WARNA_MERAH };
float jarakSensorCm[JUMLAH_JALUR]          = { 30.0, 30.0, 30.0, 30.0 };

int jalurHijauSaatIni  = 0; // 0 = tidak ada yang hijau (semua merah), 1..4 = ID jalur yang sedang hijau/kuning
int jalurTargetCloud   = 0; // 0 = semua merah, 1..4 = ID jalur yang diminta hijau oleh Cloud/Fallback
long seqTerakhir       = -1;

FaseSiklus faseSaatIni          = FASE_SEMUA_MERAH;
unsigned long waktuMulaiFaseMs  = 0;
unsigned long waktuCmdTerakhirMs = 0;
bool dalamModeFallback          = true; // Saat baru menyala sebelum ada cmd cloud, mulai dengan aman

// Variabel pewaktu non-blocking
int indeksSensorGiliran              = 0;
unsigned long waktuBacaSensorTerakhir = 0;
unsigned long waktuPublishTerakhir    = 0;
unsigned long waktuCobaMqttTerakhir   = 0;

// ============================================================================
// 4. OBJEK KONEKSI WI-FI & MQTT (EMQX CLOUD TLS / NON-TLS)
// ============================================================================
#if MQTT_USE_TLS
  WiFiClientSecure espClient;
#else
  WiFiClient espClient;
#endif
PubSubClient mqttClient(espClient);

String topicSensor = String("smarttraffic/") + DEVICE_ID + "/sensor";
String topicCmd    = String("smarttraffic/") + DEVICE_ID + "/cmd";
String topicState  = String("smarttraffic/") + DEVICE_ID + "/state";
String topicStatus = String("smarttraffic/") + DEVICE_ID + "/status";

// Deklarasi fungsi
void terapkanLedFisik();
void publishStateAktual();

// ============================================================================
// 5. FUNGSI KONVERSI & PENGAMAN PERANGKAT KERAS LED
// ============================================================================
const char* warnaKeString(WarnaLampu w) {
  switch (w) {
    case WARNA_HIJAU:  return "hijau";
    case WARNA_KUNING: return "kuning";
    default:           return "merah";
  }
}

/**
 * Pengaman perangkat keras berlapis (Invarian Keselamatan Bagian 8):
 * - Memastikan pada tiap jalur hanya 1 warna yang menyala.
 * - Memastikan di seluruh persimpangan maksimal hanya 1 jalur yang bukan merah.
 *   Bila terjadi anomali memori/logika di mana >1 jalur hendak hijau/kuning,
 *   paksa seluruh jalur menjadi MERAH.
 */
void terapkanLedFisik() {
  int jumlahJalurAktif = 0;
  for (int i = 0; i < JUMLAH_JALUR; i++) {
    if (statusLampuAktual[i] != WARNA_MERAH) {
      jumlahJalurAktif++;
    }
  }

  // Pengaman keras: tidak boleh lebih dari 1 jalur yang hijau/kuning bersamaan
  if (jumlahJalurAktif > 1) {
    Serial.println(F("[SAFETY GUARD] Terdeteksi >1 jalur aktif bersamaan! Memaksa SEMUA MERAH."));
    for (int i = 0; i < JUMLAH_JALUR; i++) {
      statusLampuAktual[i] = WARNA_MERAH;
    }
  }

  // Tulis output ke pin GPIO
  for (int i = 0; i < JUMLAH_JALUR; i++) {
    digitalWrite(JALUR[i].merahPin,  (statusLampuAktual[i] == WARNA_MERAH)  ? HIGH : LOW);
    digitalWrite(JALUR[i].kuningPin, (statusLampuAktual[i] == WARNA_KUNING) ? HIGH : LOW);
    digitalWrite(JALUR[i].hijauPin,  (statusLampuAktual[i] == WARNA_HIJAU)  ? HIGH : LOW);
  }
}

/**
 * Mengatur seluruh jalur ke MERAH kecuali 1 jalur tertentu (1..4).
 */
void setWarnaJalurTunggal(int idJalur, WarnaLampu warna) {
  for (int i = 0; i < JUMLAH_JALUR; i++) {
    if (JALUR[i].id == idJalur) {
      statusLampuAktual[i] = warna;
    } else {
      statusLampuAktual[i] = WARNA_MERAH;
    }
  }
  terapkanLedFisik();
  publishStateAktual();
}

// ============================================================================
// 6. PEMBACAAN SENSOR HC-SR04 DENGAN FILTER MEDIAN-3
// ============================================================================
float bacaSatuSampelCm(uint8_t trigPin, uint8_t echoPin) {
  digitalWrite(trigPin, LOW);
  delayMicroseconds(2);
  digitalWrite(trigPin, HIGH);
  delayMicroseconds(10);
  digitalWrite(trigPin, LOW);

  // Timeout 25.000 us (~425 cm) agar tidak menahan loop terlalu lama
  long durasiUs = pulseIn(echoPin, HIGH, 25000);
  if (durasiUs <= 0) {
    return 100.0; // Tidak ada pantulan dekat -> anggap kosong (100 cm)
  }
  float jarak = (durasiUs * 0.0343f) / 2.0f;
  if (jarak < 1.0f || jarak > 400.0f) {
    return 100.0f;
  }
  return jarak;
}

float hitungMedian3(float a, float b, float c) {
  if ((a >= b && a <= c) || (a <= b && a >= c)) return a;
  if ((b >= a && b <= c) || (b <= a && b >= c)) return b;
  return c;
}

float bacaSensorMedian3(int idx) {
  float s1 = bacaSatuSampelCm(JALUR[idx].trigPin, JALUR[idx].echoPin);
  delayMicroseconds(1500);
  float s2 = bacaSatuSampelCm(JALUR[idx].trigPin, JALUR[idx].echoPin);
  delayMicroseconds(1500);
  float s3 = bacaSatuSampelCm(JALUR[idx].trigPin, JALUR[idx].echoPin);
  return hitungMedian3(s1, s2, s3);
}

// ============================================================================
// 7. KOMUNIKASI MQTT (PUBLISH SENSOR, STATE, DAN CALLBACK CMD)
// ============================================================================
void publishSensorData() {
  if (!mqttClient.connected()) return;

  StaticJsonDocument<384> doc;
  doc["ts"] = (unsigned long)(millis() / 1000);
  JsonArray lanes = doc.createNestedArray("lanes");

  for (int i = 0; i < JUMLAH_JALUR; i++) {
    JsonObject lane = lanes.createNestedObject();
    lane["id"] = JALUR[i].id;
    lane["dist_cm"] = serialized(String(jarakSensorCm[i], 1));
  }

  char buffer[384];
  size_t len = serializeJson(doc, buffer, sizeof(buffer));
  mqttClient.publish(topicSensor.c_str(), (const uint8_t*)buffer, len, false);
}

void publishStateAktual() {
  if (!mqttClient.connected()) return;

  StaticJsonDocument<384> doc;
  doc["seq"] = (seqTerakhir >= 0) ? seqTerakhir : 0;
  doc["fallback"] = dalamModeFallback;
  JsonArray lanes = doc.createNestedArray("lanes");

  for (int i = 0; i < JUMLAH_JALUR; i++) {
    JsonObject lane = lanes.createNestedObject();
    lane["id"] = JALUR[i].id;
    lane["light"] = warnaKeString(statusLampuAktual[i]);
  }

  char buffer[384];
  size_t len = serializeJson(doc, buffer, sizeof(buffer));
  // Retain = true agar worker/dashboard yang baru terhubung tahu state lampu terakhir
  mqttClient.publish(topicState.c_str(), (const uint8_t*)buffer, len, true);
}

/**
 * Callback ketika pesan masuk pada topik `smarttraffic/{device_id}/cmd`.
 * Contoh payload:
 * { "seq": 128, "lanes": [ { "id": 1, "light": "hijau" }, { "id": 2, "light": "merah" }, ... ], "green_s": 10 }
 */
void onMqttMessage(char* topic, byte* payload, unsigned int length) {
  if (String(topic) != topicCmd) return;

  StaticJsonDocument<512> doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.print(F("[MQTT] Payload cmd JSON tidak valid: "));
    Serial.println(err.c_str());
    return;
  }

  long seq = doc["seq"] | -1;
  // Perbarui timestamp perintah terakhir setiap kali paket cmd valid diterima (agar tidak terkena timeout 10s saat fase panjang)
  waktuCmdTerakhirMs = millis();

  // Buang perintah duplikat/lama kecuali jika seq di-reset dari worker (mis. seq <= 1)
  if (seq > 1 && seq == seqTerakhir && !dalamModeFallback) {
    return;
  }

  JsonArray lanes = doc["lanes"].as<JsonArray>();
  if (lanes.isNull()) return;

  int jumlahHijauDiminta = 0;
  int idHijauDiminta     = 0;
  int idKuningDiminta    = 0;

  for (JsonObject lane : lanes) {
    int id = lane["id"] | 0;
    const char* light = lane["light"] | "merah";
    if (id < 1 || id > JUMLAH_JALUR) continue;

    if (strcmp(light, "hijau") == 0) {
      jumlahHijauDiminta++;
      idHijauDiminta = id;
    } else if (strcmp(light, "kuning") == 0) {
      idKuningDiminta = id;
    }
  }

  // INVARIAN KESELAMATAN 8.1: Tolak perintah jika meminta >1 jalur hijau bersamaan!
  if (jumlahHijauDiminta > 1) {
    Serial.println(F("[SAFETY GUARD] Perintah Cloud ditolak karena meminta >1 jalur HIJAU bersamaan!"));
    jalurTargetCloud = 0; // Paksa menuju semua merah
    return;
  }

  seqTerakhir = seq;
  waktuCmdTerakhirMs = millis();

  if (dalamModeFallback) {
    Serial.println(F("[CLOUD] Perintah Cloud diterima, keluar dari Mode Fallback Lokal."));
    dalamModeFallback = false;
  }

  if (jumlahHijauDiminta == 1) {
    jalurTargetCloud = idHijauDiminta;
  } else if (idKuningDiminta > 0 && idKuningDiminta == jalurHijauSaatIni) {
    // Cloud secara eksplisit meminta jalur aktif masuk ke kuning/merah
    jalurTargetCloud = 0;
  } else {
    // Cloud meminta semua merah
    jalurTargetCloud = 0;
  }
}

// ============================================================================
// 8. MESIN TRANSISI KESELAMATAN & FALLBACK LOKAL (NON-BLOCKING)
// ============================================================================
/**
 * Mengelola urutan transisi lampu secara aman:
 * - Tidak ada lompatan langsung dari Jalur X Hijau ke Jalur Y Hijau.
 * - Setiap pergantian wajib melewati:
 *   FASE_HIJAU (Jalur X) -> FASE_KUNING (Jalur X, 2 dtk) -> FASE_SEMUA_MERAH (1 dtk) -> FASE_HIJAU (Jalur Y).
 * - Bila koneksi ke Cloud/Worker terputus > 10 detik, otomatis menjalankan siklus tetap lokal (Opsi A).
 */
void jalankanMesinLampu() {
  unsigned long sekarang = millis();

  // 1. Cek apakah harus masuk Mode Fallback Lokal (Opsi A)
  bool koneksiPutus = (WiFi.status() != WL_CONNECTED) || (!mqttClient.connected());
  bool cmdKadaluarsa = (sekarang - waktuCmdTerakhirMs) > FALLBACK_TIMEOUT_MS;

  if (!dalamModeFallback && (koneksiPutus || cmdKadaluarsa)) {
    dalamModeFallback = true;
    Serial.println(F("[FALLBACK] Koneksi/cmd Cloud terputus >10s. Mengaktifkan Siklus Tetap Lokal (Opsi A)."));
    if (jalurTargetCloud < 1 || jalurTargetCloud > JUMLAH_JALUR) {
      jalurTargetCloud = (jalurHijauSaatIni >= 1 && jalurHijauSaatIni <= JUMLAH_JALUR) ? jalurHijauSaatIni : 1;
    }
  }

  // 2. Jika dalam Mode Fallback Lokal (Opsi A), rotasikan target jalur tiap DURASI_HIJAU_FALLBACK_MS
  if (dalamModeFallback && faseSaatIni == FASE_HIJAU) {
    if (sekarang - waktuMulaiFaseMs >= DURASI_HIJAU_FALLBACK_MS) {
      int jalurBerikutnya = (jalurHijauSaatIni % JUMLAH_JALUR) + 1;
      jalurTargetCloud = jalurBerikutnya;
    }
  }

  // 3. Evaluasi state machine transisi lampu
  switch (faseSaatIni) {
    case FASE_SEMUA_MERAH:
      // Pastikan waktu minimum SEMUA MERAH (1 detik) sudah terpenuhi sebelum mengizinkan jalur baru hijau
      if (sekarang - waktuMulaiFaseMs >= DURASI_SEMUA_MERAH_MS) {
        if (dalamModeFallback && (jalurTargetCloud < 1 || jalurTargetCloud > JUMLAH_JALUR)) {
          jalurTargetCloud = 1;
        }
        if (jalurTargetCloud >= 1 && jalurTargetCloud <= JUMLAH_JALUR) {
          jalurHijauSaatIni = jalurTargetCloud;
          faseSaatIni = FASE_HIJAU;
          waktuMulaiFaseMs = sekarang;
          setWarnaJalurTunggal(jalurHijauSaatIni, WARNA_HIJAU);
          Serial.printf("[TRANSISI] Jalur %d -> HIJAU\n", jalurHijauSaatIni);
        }
      }
      break;

    case FASE_HIJAU:
      // Jika target berubah ke jalur lain (atau diminta semua merah), wajib masuk FASE_KUNING dulu
      if (jalurTargetCloud != jalurHijauSaatIni) {
        faseSaatIni = FASE_KUNING;
        waktuMulaiFaseMs = sekarang;
        setWarnaJalurTunggal(jalurHijauSaatIni, WARNA_KUNING);
        Serial.printf("[TRANSISI] Jalur %d -> KUNING (2 detik)\n", jalurHijauSaatIni);
      }
      break;

    case FASE_KUNING:
      // Tunggu sampai durasi KUNING (2 detik) selesai, lalu masuk FASE_SEMUA_MERAH (1 detik)
      if (sekarang - waktuMulaiFaseMs >= DURASI_KUNING_MS) {
        faseSaatIni = FASE_SEMUA_MERAH;
        waktuMulaiFaseMs = sekarang;
        setWarnaJalurTunggal(0, WARNA_MERAH); // Semua jalur merah
        Serial.println(F("[TRANSISI] SEMUA MERAH (1 detik)"));
      }
      break;
  }
}

// ============================================================================
// 9. KONEKSI WI-FI & RECONNECT MQTT OTOMATIS (DENGAN LAST WILL)
// ============================================================================
void pastikanKoneksiWifiDanMqtt() {
  if (WiFi.status() != WL_CONNECTED) {
    return; // Wi-Fi auto-reconnect ditangani oleh stack ESP32
  }

  if (!mqttClient.connected()) {
    unsigned long sekarang = millis();
    if (sekarang - waktuCobaMqttTerakhir >= INTERVAL_RECONNECT_MQTT_MS) {
      waktuCobaMqttTerakhir = sekarang;

      String clientId = String("esp32-") + DEVICE_ID + "-" + String(random(1000, 9999));
      Serial.printf("[MQTT] Menghubungkan ke %s:%d sebagai %s ...\n", MQTT_BROKER, MQTT_PORT, clientId.c_str());

      // Connect dengan Last Will & Testament (LWT): topicStatus, QoS 1, Retain = true, payload = "offline"
      bool berhasil = mqttClient.connect(
        clientId.c_str(),
        MQTT_USERNAME,
        MQTT_PASSWORD,
        topicStatus.c_str(),
        1,
        true,
        "offline"
      );

      if (berhasil) {
        Serial.println(F("[MQTT] Terhubung ke Broker EMQX!"));
        // Publish status online (Retain = true)
        mqttClient.publish(topicStatus.c_str(), "online", true);
        // Subscribe ke topik perintah dengan QoS 1
        mqttClient.subscribe(topicCmd.c_str(), 1);
        // Kirim state lampu aktual saat ini
        publishStateAktual();
      } else {
        Serial.printf("[MQTT] Gagal konek (rc=%d). Coba lagi dalam 3 detik.\n", mqttClient.state());
      }
    }
  } else {
    mqttClient.loop();
  }
}

// ============================================================================
// 10. SETUP & LOOP UTAMA
// ============================================================================
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println(F("\n======================================================="));
  Serial.println(F("Smart Adaptive Traffic Light ESP32 - 4 Jalur (MQTT I/O)"));
  Serial.println(F("======================================================="));

  // 1. Konfigurasi Pin GPIO & Keadaan Awal Wajib SEMUA MERAH (Invarian 8.4)
  for (int i = 0; i < JUMLAH_JALUR; i++) {
    pinMode(JALUR[i].trigPin, OUTPUT);
    digitalWrite(JALUR[i].trigPin, LOW);
    pinMode(JALUR[i].echoPin, INPUT);

    pinMode(JALUR[i].merahPin, OUTPUT);
    pinMode(JALUR[i].kuningPin, OUTPUT);
    pinMode(JALUR[i].hijauPin, OUTPUT);

    statusLampuAktual[i] = WARNA_MERAH;
  }
  terapkanLedFisik();
  waktuMulaiFaseMs = millis();

  // 2. Mulai Koneksi Wi-Fi
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.printf("[WIFI] Menghubungkan ke SSID: %s\n", WIFI_SSID);

  // 3. Konfigurasi TLS & MQTT EMQX
  #if MQTT_USE_TLS
    espClient.setInsecure(); // Mengizinkan koneksi TLS ke EMQX Cloud tanpa perlu memuat bundel CA besar
  #endif
  mqttClient.setServer(MQTT_BROKER, MQTT_PORT);
  mqttClient.setCallback(onMqttMessage);
  mqttClient.setBufferSize(512);
  mqttClient.setKeepAlive(15);
}

void loop() {
  unsigned long sekarang = millis();

  // 1. Jalankan mesin keselamatan & transisi lampu (prioritas tertinggi, tidak boleh terblokir)
  jalankanMesinLampu();

  // 2. Kelola koneksi Wi-Fi & MQTT
  pastikanKoneksiWifiDanMqtt();

  // 3. Baca 1 sensor HC-SR04 secara bergantian tiap 120 ms (hindari crosstalk ultrasonik)
  if (sekarang - waktuBacaSensorTerakhir >= INTERVAL_BACA_PER_SENSOR_MS) {
    waktuBacaSensorTerakhir = sekarang;
    jarakSensorCm[indeksSensorGiliran] = bacaSensorMedian3(indeksSensorGiliran);
    indeksSensorGiliran = (indeksSensorGiliran + 1) % JUMLAH_JALUR;
  }

  // 4. Publish data keempat sensor ke topik `smarttraffic/{device_id}/sensor` tiap 500 ms
  if (sekarang - waktuPublishTerakhir >= INTERVAL_PUBLISH_SENSOR_MS) {
    waktuPublishTerakhir = sekarang;
    publishSensorData();
  }
}
