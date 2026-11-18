<?php
/**
 * Klien MQTT 3.1.1 Ringan (Mendukung TLS port 8883 EMQX Cloud & TCP port 1883)
 * Dirancang agar Worker PHP dapat berjalan langsung di XAMPP tanpa instalasi ekstensi tambahan.
 */

class SimpleMqttClient {
    private string $host;
    private int $port;
    private bool $useTls;
    private string $clientId;
    private ?string $username = null;
    private ?string $password = null;
    private int $keepAlive = 20;

    /** @var resource|null */
    private $socket = null;
    private int $lastPingTime = 0;
    private int $packetId = 1;
    private string $readBuffer = '';

    /** @var callable|null */
    private $onMessageCallback = null;

    public function __construct(string $host, int $port = 8883, bool $useTls = true, string $clientId = '') {
        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;
        $this->clientId = $clientId ?: ('php-worker-' . bin2hex(random_bytes(4)));
    }

    public function setCredentials(?string $username, ?string $password): void {
        $this->username = $username;
        $this->password = $password;
    }

    public function onMessage(callable $callback): void {
        $this->onMessageCallback = $callback;
    }

    public function isConnected(): bool {
        return is_resource($this->socket) && !feof($this->socket);
    }

    public function connect(bool $cleanSession = true): bool {
        $this->disconnect();

        $scheme = $this->useTls ? 'tls' : 'tcp';
        $address = "{$scheme}://{$this->host}:{$this->port}";

        $contextOptions = [];
        if ($this->useTls) {
            $contextOptions['ssl'] = [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ];
        }

        $context = stream_context_create($contextOptions);
        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            8.0,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            echo "[MQTT ERROR] Gagal membuka soket ke {$address}: ({$errno}) {$errstr}\n";
            return false;
        }

        stream_set_timeout($this->socket, 5);

        // Susun paket CONNECT MQTT 3.1.1
        $varHeader = $this->encodeString('MQTT'); // Nama protokol
        $varHeader .= chr(4);                     // Level protokol 4 (MQTT 3.1.1)

        $connectFlags = 0;
        if ($cleanSession) {
            $connectFlags |= 0x02;
        }
        if ($this->username !== null && $this->username !== '') {
            $connectFlags |= 0x80;
            if ($this->password !== null && $this->password !== '') {
                $connectFlags |= 0x40;
            }
        }
        $varHeader .= chr($connectFlags);
        $varHeader .= pack('n', $this->keepAlive);

        $payload = $this->encodeString($this->clientId);
        if ($connectFlags & 0x80) {
            $payload .= $this->encodeString($this->username ?? '');
        }
        if ($connectFlags & 0x40) {
            $payload .= $this->encodeString($this->password ?? '');
        }

        $packet = chr(0x10) . $this->encodeLength(strlen($varHeader) + strlen($payload)) . $varHeader . $payload;
        if (!$this->writeRaw($packet)) {
            $this->disconnect();
            return false;
        }

        // Baca CONNACK (4 byte: 0x20, 0x02, flags, returnCode)
        $header = fread($this->socket, 4);
        if ($header === false || strlen($header) < 4 || ord($header[0]) !== 0x20) {
            echo "[MQTT ERROR] Tidak menerima CONNACK yang valid dari broker.\n";
            $this->disconnect();
            return false;
        }

        $returnCode = ord($header[3]);
        if ($returnCode !== 0) {
            echo "[MQTT ERROR] Broker menolak koneksi (CONNACK kode: {$returnCode}). Periksa username/password EMQX.\n";
            $this->disconnect();
            return false;
        }

        // Set non-blocking untuk loop utama
        stream_set_blocking($this->socket, false);
        $this->readBuffer = '';
        $this->lastPingTime = time();
        return true;
    }

    public function subscribe(string $topic, int $qos = 1): bool {
        if (!$this->isConnected()) {
            return false;
        }
        $id = $this->nextPacketId();
        $payload = pack('n', $id) . $this->encodeString($topic) . chr($qos & 0x03);
        $packet = chr(0x82) . $this->encodeLength(strlen($payload)) . $payload;
        return $this->writeRaw($packet);
    }

    public function publish(string $topic, string $message, int $qos = 0, bool $retain = false): bool {
        if (!$this->isConnected()) {
            return false;
        }
        $cmd = 0x30 | (($qos & 0x03) << 1) | ($retain ? 0x01 : 0x00);
        $varHeader = $this->encodeString($topic);
        if ($qos > 0) {
            $varHeader .= pack('n', $this->nextPacketId());
        }
        $body = $varHeader . $message;
        $packet = chr($cmd) . $this->encodeLength(strlen($body)) . $body;
        return $this->writeRaw($packet);
    }

    /**
     * Memproses paket masuk dan menjaga Keep-Alive (non-blocking).
     */
    public function loopOnce(): bool {
        if (!$this->isConnected()) {
            return false;
        }

        // Baca data baru yang tersedia di soket
        $chunk = @fread($this->socket, 8192);
        if ($chunk !== false && $chunk !== '') {
            $this->readBuffer .= $chunk;
        } elseif ($chunk === false || feof($this->socket)) {
            $this->disconnect();
            return false;
        }

        // Proses seluruh paket MQTT utuh di dalam buffer
        while (strlen($this->readBuffer) >= 2) {
            $firstByte = ord($this->readBuffer[0]);
            $offset = 1;
            $multiplier = 1;
            $remainingLength = 0;

            do {
                if ($offset >= strlen($this->readBuffer)) {
                    // Paket belum lengkap, tunggu pembacaan berikutnya
                    break 2;
                }
                $digit = ord($this->readBuffer[$offset++]);
                $remainingLength += ($digit & 127) * $multiplier;
                $multiplier *= 128;
            } while (($digit & 128) !== 0);

            $totalPacketLength = $offset + $remainingLength;
            if (strlen($this->readBuffer) < $totalPacketLength) {
                // Isi paket belum lengkap
                break;
            }

            $packetBody = substr($this->readBuffer, $offset, $remainingLength);
            $this->readBuffer = substr($this->readBuffer, $totalPacketLength);

            $packetType = ($firstByte & 0xF0) >> 4;
            $this->handleIncomingPacket($packetType, $firstByte & 0x0F, $packetBody);
        }

        // Kirim PINGREQ berkala agar koneksi tidak diputus oleh EMQX
        if ((time() - $this->lastPingTime) >= max(5, (int)($this->keepAlive * 0.7))) {
            $this->writeRaw(chr(0xC0) . chr(0x00));
            $this->lastPingTime = time();
        }

        return true;
    }

    private function handleIncomingPacket(int $type, int $flags, string $body): void {
        if ($type === 3) {
            // Paket PUBLISH masuk
            if (strlen($body) < 2) {
                return;
            }
            $topicLen = unpack('n', substr($body, 0, 2))[1];
            $topic = substr($body, 2, $topicLen);
            $pos = 2 + $topicLen;

            $qos = ($flags >> 1) & 0x03;
            if ($qos > 0) {
                if (strlen($body) < $pos + 2) {
                    return;
                }
                $packetId = unpack('n', substr($body, $pos, 2))[1];
                $pos += 2;
                // Balas PUBACK untuk QoS 1
                if ($qos === 1) {
                    $this->writeRaw(chr(0x40) . chr(0x02) . pack('n', $packetId));
                }
            }

            $payload = substr($body, $pos);
            if ($this->onMessageCallback !== null) {
                ($this->onMessageCallback)($topic, $payload);
            }
        }
    }

    public function disconnect(): void {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, chr(0xE0) . chr(0x00));
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->readBuffer = '';
    }

    private function writeRaw(string $data): bool {
        if (!$this->isConnected()) {
            return false;
        }
        $written = @fwrite($this->socket, $data);
        return $written !== false && $written === strlen($data);
    }

    private function encodeString(string $str): string {
        return pack('n', strlen($str)) . $str;
    }

    private function encodeLength(int $len): string {
        $out = '';
        do {
            $digit = $len % 128;
            $len = intdiv($len, 128);
            if ($len > 0) {
                $digit |= 0x80;
            }
            $out .= chr($digit);
        } while ($len > 0);
        return $out;
    }

    private function nextPacketId(): int {
        $this->packetId = ($this->packetId % 65535) + 1;
        return $this->packetId;
    }
}
?>
