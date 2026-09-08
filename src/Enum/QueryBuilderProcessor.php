<?php

declare(strict_types=1);

namespace OpenStudio\QueryBuilderBundle\Enum;

enum QueryBuilderProcessor: string
{
    case JsonLogic = 'jsonLogic';
    case Parameterized = 'parameterized';

    /**
     * The condition tree as react-querybuilder holds it, for a consumer that compiles the rules
     * itself: groups as {combinator, not, rules}, rules as {field, operator, value}, without the
     * ids the editor adds for its own bookkeeping. Not JsonLogic: the operator names are the
     * editor's own.
     */
    case Native = 'native';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $processor): string => $processor->value, self::cases());
    }
}
