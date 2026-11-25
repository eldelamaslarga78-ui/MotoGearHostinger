<?php
declare(strict_types=1);
/*
 * Contenedor de Inyección de Dependencias mínimo.
 * Registra fábricas/singletons y resuelve constructores por reflection (auto-wiring).
 */
namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, callable(self):mixed> */
    private array $fabricas = [];
    /** @var array<string, mixed> */
    private array $singletons = [];

    public function set(string $id, callable $fabrica): void
    {
        $this->fabricas[$id] = $fabrica;
    }

    public function singleton(string $id, callable $fabrica): void
    {
        $this->fabricas[$id] = function (self $c) use ($id, $fabrica) {
            if (!array_key_exists($id, $this->singletons)) {
                $this->singletons[$id] = $fabrica($c);
            }
            return $this->singletons[$id];
        };
    }

    public function has(string $id): bool
    {
        return isset($this->fabricas[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->fabricas[$id])) {
            return $this->make($id);
        }
        return ($this->fabricas[$id])($this);
    }

    /** Resuelve una clase por su constructor (parámetros tipados). */
    public function make(string $clase): object
    {
        $ref = new ReflectionClass($clase);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $tipo = $p->getType();
            if ($tipo instanceof ReflectionNamedType && !$tipo->isBuiltin()) {
                $nombre = $tipo->getName();
                $args[] = $this->has($nombre) ? $this->get($nombre) : $this->make($nombre);
            } elseif ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                throw new RuntimeException('No se puede resolver el parámetro ' . $p->getName() . ' de ' . $clase);
            }
        }
        return $ref->newInstanceArgs($args);
    }
}
