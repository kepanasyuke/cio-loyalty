<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\Brand;

final class BrandContext
{
    private ?Brand $brand = null;

    public function set(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function has(): bool
    {
        return $this->brand !== null;
    }

    public function get(): Brand
    {
        return $this->brand ?? throw new DomainException('Контекст бренда не установлен.', 500);
    }

    public function id(): int
    {
        return $this->get()->getKey();
    }
}
