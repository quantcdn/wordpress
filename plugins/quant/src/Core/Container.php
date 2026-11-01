<?php

declare(strict_types=1);

namespace Quant\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    private array $bindings = [];
    private array $singletons = [];
    private array $instances = [];

    public function bind(string $key, Closure|string $resolver): void
    {
        $this->bindings[$key] = $resolver;
    }

    public function singleton(string $key, Closure|string $resolver): void
    {
        $this->singletons[$key] = $resolver;
    }

    public function instance(string $key, object $instance): void
    {
        $this->instances[$key] = $instance;
    }

    public function get(string $key): mixed
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->singletons[$key])) {
            $this->instances[$key] = $this->resolve($this->singletons[$key]);
            unset($this->singletons[$key]);
            return $this->instances[$key];
        }

        if (isset($this->bindings[$key])) {
            return $this->resolve($this->bindings[$key]);
        }

        throw new RuntimeException(esc_html("No binding found for: {$key}"));
    }

    public function has(string $key): bool
    {
        return isset($this->bindings[$key])
            || isset($this->singletons[$key])
            || isset($this->instances[$key]);
    }

    public function make(string $class): object
    {
        if ($this->has($class)) {
            return $this->get($class);
        }

        return $this->build($class);
    }

    private function resolve(Closure|string $resolver): mixed
    {
        if ($resolver instanceof Closure) {
            return $resolver($this);
        }

        return $this->build($resolver);
    }

    private function build(string $class): object
    {
        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException(esc_html("Class {$class} is not instantiable"));
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                    continue;
                }
                throw new RuntimeException(
                    esc_html("Cannot resolve parameter: {$param->getName()} in {$class}")
                );
            }

            $dependencies[] = $this->make($type->getName());
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
