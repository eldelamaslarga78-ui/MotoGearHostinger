<?php
declare(strict_types=1);
/*
 * Abstracción de la petición HTTP (ruta ?r=, método, body, IP, archivos).
 */
namespace App\Core;

final class Request
{
    /** @param array<string,mixed> $get  @param array<string,mixed> $server  @param array<string,mixed> $files */
    public function __construct(
        private array $get,
        private array $server,
        private array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_SERVER, $_FILES);
    }

    public function ruta(): string
    {
        return (string)($this->get['r'] ?? '');
    }

    public function method(): string
    {
        return (string)($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function ip(): string
    {
        return substr((string)($this->server['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public function get(string $clave, $defecto = ''): mixed
    {
        return $this->get[$clave] ?? $defecto;
    }

    public function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    public function files(): array
    {
        return $this->files;
    }
}
