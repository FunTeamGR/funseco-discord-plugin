<?php
namespace Funseco\Discord\Tests\Feature;

use Evenement\EventEmitter;
use React\Stream\DuplexStreamInterface;
use React\Stream\WritableStreamInterface;

/** In-memory duplex stream: records writes, the test pushes input with emit('data', [...]). */
class FakeDuplex extends EventEmitter implements DuplexStreamInterface
{
    public array $written = [];
    public bool $closedByServer = false;
    private bool $closed = false;

    public function write($data): bool
    {
        $this->written[] = $data;

        return true;
    }

    public function end($data = null): void
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closedByServer = true;
        $this->emit('close');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function pause(): void
    {
    }

    public function resume(): void
    {
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function replies(): array
    {
        return array_map(fn ($l) => json_decode(trim($l), true), $this->written);
    }
}
