<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos de marketing (cupones, promociones, carritos).
 */
namespace App\Repositories\Contracts;

interface MarketingRepositoryInterface
{
    public function cupon(string $codigo): ?array;
    public function usosPorCliente(string $codigo, int $clienteId): int;

    public function promocionesActivas(): array;
    public function promocionItems(int $promoId): array;
    public function promociones(): array;
    public function promocionNombre(int $id): ?string;
    public function borrarPromocion(int $id): void;
    public function insertarPromocion(array $v): int;
    public function actualizarPromocion(array $v, int $id): void;
    public function borrarPromocionItems(int $id): void;
    public function insertarPromocionItem(int $promoId, ?int $productoId, ?string $categoriaId): void;

    public function carritoPorToken(string $token): int;
    public function insertarCarrito(string $token, ?int $clienteId, string $email, ?string $telefono): int;
    public function actualizarCarrito(int $id, string $email, ?string $telefono): void;
    public function borrarCarritoItems(int $id): void;
    public function variantePrecio(int $vid): ?array;
    public function insertarCarritoItem(int $carritoId, int $vid, int $cant, int $precio): void;
    public function fijarSubtotal(int $id, int $subtotal): void;
    public function contarCarritosAbandonados(): int;
    public function listarCarritosAbandonados(int $limite, int $offset): array;
    public function carrito(int $id): ?array;
    public function marcarCarritoRecuperado(int $id): void;
    public function resumenCarritos(): array;
    public function marcarAbandonos(int $horas): int;
    public function carritosParaRecordatorio(int $horas): array;
    public function marcarRecordatorio(int $id): void;

    public function clientePorEmail(string $email): int;
    public function pedidosEntregadosDe(int $clienteId): int;
}
