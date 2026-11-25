<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos de pedidos (pedidos, items, pagos, envíos,
 * facturas, eventos y transportadoras).
 */
namespace App\Repositories\Contracts;

interface PedidoRepositoryInterface
{
    public function contar(string $where, array $args): int;
    public function listar(string $where, array $args, int $limite, int $offset): array;

    public function pedido(int $id): ?array;
    public function itemsDe(int $id): array;
    public function pagosDe(int $id): array;
    public function envioDe(int $id): ?array;
    public function facturaDe(int $id): ?array;
    public function eventosDe(int $id): array;
    public function transportadorasActivas(): array;

    public function pedidoBloqueado(int $id): ?array;
    public function estadoDe(int $id): ?string;
    public function devolverStock(int $pedidoId, ?int $usuarioId): void;
    public function revertirCupon(string $codigo): void;
    public function marcarEntregado(int $id): void;
    public function fijarEstado(int $id, string $estado): void;
    public function insertarEvento(int $pedidoId, ?string $antes, string $despues, ?string $nota, ?int $usuarioId): void;

    public function insertarPago(int $pedidoId, string $pasarela, ?string $referencia, string $estado, int $monto): void;
    public function fijarPago(int $id, string $metodo): void;
    public function fijarNota(int $id, ?string $nota): void;
    public function upsertEnvio(int $id, int $transportadora, string $guia): void;

    public function consecutivoFactura(): int;
    public function fijarConsecutivo(int $n): void;
    public function insertarFactura(int $pedidoId, int $numero, string $completo, int $subtotal, int $descuento, int $impuestos, int $total): void;

    public function transportadoras(): array;
    public function insertarTransportadora(string $nombre, string $url, int $activo): void;
    public function actualizarTransportadora(int $id, string $nombre, string $url, int $activo): void;

    public function pendientesVencidos(): array;
}
