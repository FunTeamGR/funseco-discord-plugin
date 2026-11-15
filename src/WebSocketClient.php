<?php
namespace Funseco\Discord;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\DuplexStreamInterface;

/**
 * Minimal RFC 6455 client over an already connected (TLS) duplex stream: handshake, text frames,
 * fragments, ping/pong, close. Server frames are unmasked, client frames are masked.
 */
class WebSocketClient
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_MESSAGE = 4 * 1024 * 1024;

    /** @var callable[] */
    private array $messageHandlers = [];
    /** @var callable[] */
    private array $closeHandlers = [];
    private string $buffer = '';
    private string $message = '';
    private bool $framing = false;
    private bool $closed = false;
    private ?int $closeCode = null;

    public function __construct(private DuplexStreamInterface $stream)
    {
        $stream->on('close', fn () => $this->fireClose());
        $stream->on('error', fn () => $this->fireClose());
    }

    public function onMessage(callable $cb): void
    {
        $this->messageHandlers[] = $cb;
    }

    /** Status code of the close frame the server sent, if any. */
    public function closeCode(): ?int
    {
        return $this->closeCode;
    }

    public function onClose(callable $cb): void
    {
        $this->closeHandlers[] = $cb;
    }

    /** Sends the upgrade request; resolves once the server answers 101 with the right accept key. */
    public function handshake(string $url): PromiseInterface
    {
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? '';
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $key = base64_encode(random_bytes(16));
        $deferred = new Deferred();
        $head = '';

        $onData = function (string $data) use (&$onData, &$head, $key, $deferred) {
            $head .= $data;
            if (($end = strpos($head, "\r\n\r\n")) === false) {
                if (strlen($head) > 16384) {
                    $this->stream->removeListener('data', $onData);
                    $deferred->reject(new \RuntimeException('handshake response too large'));
                }

                return;
            }
            $this->stream->removeListener('data', $onData);
            $headers = substr($head, 0, $end);
            $rest = substr($head, $end + 4);
            $expected = base64_encode(sha1($key.self::GUID, true));
            if (!preg_match('#^HTTP/1\.1 101#', $headers) || !preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($expected, '/').'\s*$/mi', $headers)) {
                $deferred->reject(new \RuntimeException('websocket handshake refused: '.strtok($headers, "\r\n")));

                return;
            }
            $this->beginFrames();
            $deferred->resolve(null);
            if ($rest !== '') {
                $this->onData($rest);
            }
        };
        $this->stream->on('data', $onData);
        $this->stream->write("GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");

        return $deferred->promise();
    }

    /** Start decoding frames (after the handshake). */
    public function beginFrames(): void
    {
        if ($this->framing) {
            return;
        }
        $this->framing = true;
        $this->stream->on('data', fn (string $d) => $this->onData($d));
    }

    public function send(string $text): void
    {
        $this->write(0x1, $text);
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->write(0x8, pack('n', 1000));
        }
        $this->stream->close();
    }

    private function write(int $opcode, string $payload): void
    {
        if ($this->closed) {
            return;
        }
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);
        $frame .= $len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126).pack('n', $len) : chr(0x80 | 127).pack('J', $len));
        $mask = random_bytes(4);
        $masked = $payload ^ str_pad('', $len, $mask);
        $this->stream->write($frame.$mask.$masked);
    }

    private function onData(string $data): void
    {
        $this->buffer .= $data;
        while (($frame = $this->nextFrame()) !== null) {
            [$fin, $opcode, $payload] = $frame;
            switch ($opcode) {
                case 0x8:
                    if (strlen($payload) >= 2) {
                        $this->closeCode = unpack('n', $payload)[1];
                    }
                    $this->close();

                    return;
                case 0x9:
                    $this->write(0xA, $payload);
                    break;
                case 0xA:
                    break;
                case 0x1:
                case 0x0:
                    $this->message .= $payload;
                    if (strlen($this->message) > self::MAX_MESSAGE) {
                        $this->message = '';
                        $this->close();

                        return;
                    }
                    if ($fin) {
                        $m = $this->message;
                        $this->message = '';
                        foreach ($this->messageHandlers as $h) {
                            $h($m);
                        }
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /** @return array{0: bool, 1: int, 2: string}|null */
    private function nextFrame(): ?array
    {
        $b = $this->buffer;
        if (strlen($b) < 2) {
            return null;
        }
        $fin = (ord($b[0]) & 0x80) !== 0;
        $opcode = ord($b[0]) & 0x0F;
        $masked = (ord($b[1]) & 0x80) !== 0;
        $len = ord($b[1]) & 0x7F;
        $off = 2;
        if ($len === 126) {
            if (strlen($b) < 4) {
                return null;
            }
            $len = unpack('n', substr($b, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($b) < 10) {
                return null;
            }
            $len = unpack('J', substr($b, 2, 8))[1];
            $off = 10;
            if ($len < 0 || $len > self::MAX_MESSAGE) {
                $this->buffer = '';
                $this->close();

                return null;
            }
        }
        $mask = '';
        if ($masked) {
            if (strlen($b) < $off + 4) {
                return null;
            }
            $mask = substr($b, $off, 4);
            $off += 4;
        }
        if (strlen($b) < $off + $len) {
            return null;
        }
        $payload = substr($b, $off, $len);
        if ($masked) {
            $payload ^= str_pad('', $len, $mask);
        }
        $this->buffer = substr($b, $off + $len);

        return [$fin, $opcode, $payload];
    }

    private function fireClose(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->closeHandlers as $h) {
            $h();
        }
    }
}
