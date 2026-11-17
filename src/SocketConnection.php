<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use Amp\Cancellation;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Throwable;

use function Amp\Socket\connect;
use function Amp\Socket\connectTls;

/**
 * A TCP connection with TLS from the start (993, 465) or after STARTTLS (587); every read waits at most $timeout.
 */
final class SocketConnection implements Connection {
    private const LINE_MAX = 1_048_576;

    private string $buffer = '';

    private function __construct(
        private readonly Socket $socket,
        private readonly float $timeout,
    ) {}

    public static function open(string $host, int $port, bool $tls, float $timeout = 30.0): self {
        $context = (new ConnectContext())->withConnectTimeout($timeout)->withTlsContext(new ClientTlsContext($host));
        try {
            $socket = $tls ? connectTls($host . ':' . $port, $context) : connect($host . ':' . $port, $context);
        } catch (Throwable $e) {
            throw new MailException(sprintf('Нет соединения с %s:%d (%s).', $host, $port, (new \ReflectionClass($e))->getShortName()));
        }
        return new self($socket, $timeout);
    }

    public function write(string $data): void {
        $this->socket->write($data);
    }

    public function readLine(): ?string {
        $end = strpos($this->buffer, "
");
        while ($end === false) {
            if (strlen($this->buffer) > self::LINE_MAX) {
                throw new MailException('Слишком длинная строка ответа сервера.');
            }
            $chunk = $this->socket->read($this->cancellation());
            if ($chunk === null) {
                return null;
            }
            $this->buffer .= $chunk;
            $end = strpos($this->buffer, "
");
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);
        return rtrim($line, "");
    }

    public function read(int $length): string {
        while (strlen($this->buffer) < $length) {
            $chunk = $this->socket->read($this->cancellation());
            if ($chunk === null) {
                throw new MailException('Сервер закрыл соединение посреди ответа.');
            }
            $this->buffer .= $chunk;
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data;
    }

    public function startTls(): void {
        $this->socket->setupTls($this->cancellation());
    }

    public function close(): void {
        $this->socket->close();
    }

    private function cancellation(): Cancellation {
        return new TimeoutCancellation($this->timeout);
    }
}
