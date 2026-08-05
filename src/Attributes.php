<?php

declare(strict_types=1);

namespace Fissible\Transmark;

/**
 * A lossless escape hatch carried by every node: format-specific data
 * that has no first-class representation in the tree survives a
 * read -> write round-trip by living here instead of being dropped.
 */
final class Attributes
{
    /**
     * @param string[] $classes
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly ?string $id = null,
        private readonly array $classes = [],
        private readonly array $data = [],
    ) {
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * @return string[]
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function withData(string $key, mixed $value): self
    {
        return new self($this->id, $this->classes, [...$this->data, $key => $value]);
    }
}
