<?php

namespace App\Integrations;

class DriverDescriptor
{
    /**
     * @param CredentialField[] $credentialsSchema
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $credentialsSchema = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'credentials_schema' => array_map(
                fn(CredentialField $field) => $field->toArray(),
                $this->credentialsSchema,
            ),
        ];
    }
}
