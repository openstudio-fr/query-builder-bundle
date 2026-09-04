<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Dto;

/**
 * @see https://react-querybuilder.js.org/docs/typescript#option-lists
 */
final readonly class FieldOption
{
    public function __construct(
        public string $name,
        public ?string $label = null,
        public ?string $value = null,
    ) {
    }

    /**
     * @return array{name: string, label: string|null, value: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'value' => $this->value,
        ];
    }
}
