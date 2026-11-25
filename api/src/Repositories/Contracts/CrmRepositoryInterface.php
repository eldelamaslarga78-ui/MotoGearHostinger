<?php
declare(strict_types=1);
/*
 * Contrato de acceso a datos de CRM (clientes, direcciones, pedidos y devoluciones).
 */
namespace App\Repositories\Contracts;

interface CrmRepositoryInterface
{
    public function clientePorEmail(string $email): int;
    public function clientePorTelefono(string $tel): int;
    public function insertarCliente(string $nombre, ?string $email, ?string $tel): int;

    public function contarClientes(string $where, array $args): int;
    public function listarClientes(string $where, array $args, int $limite, int $offset): array;
    public function cliente(int $id): ?array;
    public function direccionesDe(int $clienteId): array;
    public function pedidosDe(int $clienteId): array;
    public function devolucionesDeCliente(int $clienteId): array;
    public function actualizarCliente(int $id, string $nombre, ?string $email, ?string $tel, string $tipoDoc, ?string $documento, ?string $notas, int $acepta): void;

    public function quitarPrincipalDirecciones(int $clienteId): void;
    public function insertarDireccion(int $clienteId, string $alias, string $dest, string $tel, string $dep, string $ciudad, string $dir, string $ind, int $principal): void;
    public function actualizarDireccion(int $id, int $clienteId, string $alias, string $dest, string $tel, string $dep, string $ciudad, string $dir, string $ind, int $principal): void;
    public function fusionarClientes(int $origen, int $destino): void;

    public function contarDevoluciones(string $where, array $args): int;
    public function listarDevoluciones(string $where, array $args, int $limite, int $offset): array;
    public function pedidoBloqueado(int $id): ?array;
    public function insertarDevolucion(int $pedidoId, string $motivo, string $descripcion, ?int $usuarioId): int;
    public function pedidoItem(int $itemId, int $pedidoId): ?array;
    public function yaDevuelto(int $itemId): int;
    public function insertarDevolucionItem(int $devId, int $itemId, int $cant, string $accion, int $reingresa): void;
    public function fijarMontoDevolucion(int $devId, int $monto): void;

    public function devolucionBloqueada(int $id): ?array;
    public function devolucionItems(int $devId): array;
    public function varianteDePedidoItem(int $itemId): int;
    public function sumarStock(int $vid, int $cant): void;
    public function stockDe(int $vid): int;
    public function insertarMovimientoDevolucion(int $vid, int $cant, int $stockDespues, string $motivo, int $refId, ?int $usuarioId): void;
    public function reembolsarPagos(int $pedidoId): void;
    public function marcarPedidoDevuelto(int $pedidoId): void;
    public function insertarEventoPedido(int $pedidoId, string $antes, string $despues, string $nota, ?int $usuarioId): void;
    public function fijarEstadoDevolucion(int $id, string $estado): void;
}
