<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationTester;

abstract class IntegrationTypeDescriptor
{
    abstract public function type(): string;

    abstract public function label(): string;

    /**
     * @return DriverDescriptor[]
     */
    abstract public function drivers(): array;

    abstract public function getTester(string $driverName): IntegrationTester;

    public function getDriver(string $name): ?DriverDescriptor
    {
        foreach ($this->drivers() as $driver) {
            if ($driver->name === $name) {
                return $driver;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'drivers' => array_map(
                fn(DriverDescriptor $driver) => $driver->toArray(),
                $this->drivers(),
            ),
        ];
    }
}
