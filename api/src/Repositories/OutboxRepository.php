<?php
declare(strict_types=1);
/*
 * Acceso a datos de la cola de mensajes (outbox).
 */
namespace App\Repositories;

use App\Repositories\Contracts\OutboxRepositoryInterface;
use PDO;

final class OutboxRepository implements OutboxRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function encolar(string $tipo, array $payload): int
    {
        $this->pdo->prepare('INSERT INTO outbox (tipo, payload) VALUES (?, ?)')
            ->execute([$tipo, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function pendientes(int $limite): array
    {
        $q = $this->pdo->prepare("SELECT id, tipo, payload, intentos FROM outbox WHERE estado = 'pendiente' ORDER BY id LIMIT " . (int)$limite);
        $q->execute();
        return $q->fetchAll();
    }

    public function porIds(array $ids): array
    {
        $q = $this->pdo->prepare("SELECT id, tipo, payload, intentos FROM outbox
                                   WHERE id IN (" . implode(',', $ids) . ") AND estado = 'pendiente'");
        $q->execute();
        return $q->fetchAll();
    }

    public function marcarEnviado(int $id, int $intentos): void
    {
        $this->pdo->prepare("UPDATE outbox SET estado = 'enviado', intentos = ?, enviado_en = NOW(), ultimo_error = NULL WHERE id = ?")
            ->execute([$intentos, $id]);
    }

    public function marcarEstado(int $id, int $intentos, string $estado, ?string $error): void
    {
        $this->pdo->prepare('UPDATE outbox SET estado = ?, intentos = ?, ultimo_error = ? WHERE id = ?')
            ->execute([$estado, $intentos, $error, $id]);
    }

    public function estado(): array
    {
        $r = $this->pdo->query("SELECT estado, COUNT(*) AS n FROM outbox GROUP BY estado")->fetchAll();
        $o = ['pendiente' => 0, 'enviado' => 0, 'fallido' => 0];
        foreach ($r as $x) {
            $o[(string)$x['estado']] = (int)$x['n'];
        }
        return $o;
    }
}
