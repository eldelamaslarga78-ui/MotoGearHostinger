<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos del checkout (productos/variantes con bloqueo,
 * pedidos, cupones y carritos).
 */
namespace App\Repositories\Contracts;

interface CheckoutRepositoryInterface
{
    /** @return array{productos:array, variantes:array} */
    public function cargarCatalogo(array $ids): array;

    /** @param array<string,mixed> $P @param array<string,mixed> $V */
    public function reservarStock(int $pid, array $lineas, array $P, array &$V): void;

    public function contarPedidosEntregados(int $clienteId): int;
    public function cuponBloqueado(string $codigo): ?array;
    public function insertarPedido(array $v): int;
    public function insertarEventoInicial(int $pid): void;
    public function registrarCupon(string $codigo): void;
    public function insertarCuponUso(string $codigo, int $pid, int $monto): void;
    public function vincularCarrito(string $token, int $pid): void;
    public function contarPedidosPorIp(string $ip): int;
    public function pedidoPorIdempotencia(string $idem): ?array;
}
