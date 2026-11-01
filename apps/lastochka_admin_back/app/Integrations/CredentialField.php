<?php

namespace App\Integrations;

class CredentialField
{
    public function __construct(
        public string $field,
        public string $label,
        public string $type,
        public bool $required = true,
        public ?string $placeholder = null,
        public ?array $options = null,
    ) {}

    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'options' => $this->options,
        ];
    }
}
