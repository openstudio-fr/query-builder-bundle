<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Enum;

enum QueryBuilderProcessor: string
{
    case JsonLogic = 'jsonLogic';
    case Parameterized = 'parameterized';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $processor): string => $processor->value, self::cases());
    }
}
