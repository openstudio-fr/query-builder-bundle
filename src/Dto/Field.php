<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Dto;

use OpenStudio\QueryBuilderBundle\Enum\ValueType;

final readonly class Field
{
    /**
     * @param list<FieldOption>|null $values
     */
    public function __construct(
        public string $name,
        public ValueType $type = ValueType::Text,
        public ?string $label = null,
        public ?string $labelInformation = null,
        public ?array $values = null,
    ) {
    }

    /**
     * @return array{name: string, type: string, label: string|null, values: list<array{name: string, label: string|null, value: string|null}>|null, labelInformation: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'label' => $this->label,
            'labelInformation' => $this->labelInformation,
            // An empty list is sent as null: the editor would otherwise take the field for a
            // select and offer a "List of values" operator with nothing to pick.
            'values' => null === $this->values || [] === $this->values
                ? null
                : array_map(static fn (FieldOption $option): array => $option->toArray(), $this->values),
        ];
    }
}
