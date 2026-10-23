<?php

namespace App\Domain\Sections;

/**
 * The explicit list of sections that can be put behind a coin price
 * (config/minimax.php > unlockable_sections). Keys are never invented
 * from request input: anything not listed here is unknown.
 */
class SectionRegistry
{
    /**
     * @return array<string, array{title: string, description: string, icon: string}>
     */
    public function all(): array
    {
        return config('minimax.unlockable_sections', []);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return array{title: string, description: string, icon: string}|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
