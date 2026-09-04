<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Enum;

enum ValueType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $valueType): string => $valueType->value, self::cases());
    }
}
