<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Dto;

use OpenStudio\QueryBuilderBundle\Enum\Operator;
use OpenStudio\QueryBuilderBundle\Enum\ValueType;

final readonly class Field
{
    /**
     * @param list<FieldOption>|null $values
     * @param list<Operator>|null    $operators the operators offered on this field, in that order, in
     *                                          place of the "operators" option; null leaves the field
     *                                          to the option and to the defaults of its type
     */
    public function __construct(
        public string $name,
        public ValueType $type = ValueType::Text,
        public ?string $label = null,
        public ?string $labelInformation = null,
        public ?array $values = null,
        public ?array $operators = null,
    ) {
    }

    /**
     * @return array{name: string, type: string, label: string|null, labelInformation: string|null, values: list<array{name: string, label: string|null, value: string|null}>|null, operators: list<string>|null}
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
            'operators' => null === $this->operators
                ? null
                : array_map(static fn (Operator $operator): string => $operator->value, $this->operators),
        ];
    }
}
