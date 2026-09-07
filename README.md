# Query Builder Bundle

[React Query Builder](https://react-querybuilder.js.org/) packaged as a Symfony form type. Users compose nested and/or condition groups in the browser, with typed fields, drag-and-drop and translated labels. Your application receives the result as a [JsonLogic](https://jsonlogic.com/) tree it can store and evaluate server side, or as the editor's own tree when it compiles the rules itself. The editor is a Stimulus controller that AssetMapper or Webpack Encore loads like any other, and there is no dedicated endpoint: it is one more field in a regular Symfony form.

The moving parts:

- `QueryBuilderType` extends the built-in `HiddenType`. Its form theme wraps the hidden input in a `<div>` handled by the bundled Stimulus controller, which mounts the React editor and rewrites the input value on every change.
- On submit, a model transformer decodes the JSON: your form data is a plain PHP array, or `null` when the builder is empty. It also checks the tree against the fields and operators you declared, since the payload comes from the browser. Invalid JSON, or a tree stepping outside those options, becomes a regular form error, not an exception.
- When the form is rendered with existing data, the saved tree is parsed back into the editor, so editing a stored query works out of the box.

## Requirements

- PHP 8.3+
- Symfony 7.4
- Stimulus and Twig (pulled in as dependencies)
- AssetMapper, or Webpack Encore with `@symfony/stimulus-bridge`, to load the controller (see [Assets](#assets))
- Bootstrap CSS on the pages that render the widget: the editor uses the Bootstrap theme of React Query Builder and does not ship Bootstrap itself

## Installation

The package is not on Packagist, so tell Composer where to find it:

```json
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/openstudio-fr/query-builder-bundle" }
]
```

Then require it:

```bash
composer require openstudio/query-builder-bundle:^0.1
```

If you do not use Symfony Flex, register the bundle:

```php
// config/bundles.php
return [
    // ...
    OpenStudio\QueryBuilderBundle\OpenStudioQueryBuilderBundle::class => ['all' => true],
];
```

### Assets

The bundle ships one Stimulus controller, `query-builder`, which mounts the React editor. The libraries it needs (React 19, React Query Builder 8.23 with its Bootstrap and drag-and-drop packages, React DnD with its HTML5 and touch backends) are declared as peer dependencies in [assets/package.json](assets/package.json). The application loads the controller with AssetMapper or with Webpack Encore. The bundle requires neither, so install the one you use.

#### With AssetMapper

When `symfony/asset-mapper` is installed, the bundle registers its `assets/` directory under the `@openstudio/query-builder-bundle` namespace. Enable the Stimulus controller in your `assets/controllers.json`:

```json
{
    "controllers": {
        "@openstudio/query-builder-bundle": {
            "query_builder": {
                "enabled": true,
                "fetch": "lazy"
            }
        }
    }
}
```

Then add the JavaScript dependencies to your importmap:

```bash
php bin/console importmap:require react react-dom react-dom/client react/jsx-runtime \
    react-querybuilder react-querybuilder/parseJsonLogic react-querybuilder/dist/query-builder.css \
    @react-querybuilder/bootstrap @react-querybuilder/dnd \
    react-dnd react-dnd-html5-backend react-dnd-touch-backend redux
```

`redux` is on the list on purpose. The importmap holds one version per package, and `dnd-core`, a dependency of React DnD, would otherwise bring redux 4 in first while the `@reduxjs/toolkit` used by React Query Builder needs redux 5: the controller then fails to load with "The requested module 'redux' does not provide an export named 'isAction'". Requiring `redux` yourself resolves it to the current major.

#### With Webpack Encore

Add the bundle's `assets/` directory to your `package.json` as a path dependency, so that `@symfony/stimulus-bridge` finds it in `node_modules` like any Symfony UX package:

```json
{
    "devDependencies": {
        "@openstudio/query-builder-bundle": "file:vendor/openstudio/query-builder-bundle/assets"
    }
}
```

Enable the controller in `assets/controllers.json` with the same entry as above, install the package and its peer dependencies, then rebuild:

```bash
npm install
npm install react react-dom react-querybuilder @react-querybuilder/bootstrap @react-querybuilder/dnd \
    react-dnd react-dnd-html5-backend react-dnd-touch-backend
npm run build
```

`enableStimulusBridge()` in `webpack.config.js` picks the controller up, lazy loading included, and the stylesheet it imports goes through Encore's CSS handling. The controller is written without JSX, so the React preset of Encore is not needed.

## Configuration

The bundle has no configuration file of its own. At boot it prepends:

- its form theme to Twig, so the widget renders without any `form_themes` setup on your side;
- its `assets/` directory to AssetMapper under the `@openstudio/query-builder-bundle` namespace, when AssetMapper is installed.

The default language of the editor is `kernel.default_locale`. Everything else is set per field through the form options below.

## Usage

### Form integration

Add the type to any form and describe the queryable fields:

```php
use OpenStudio\QueryBuilderBundle\Dto\Field;
use OpenStudio\QueryBuilderBundle\Enum\ValueType;
use OpenStudio\QueryBuilderBundle\Form\QueryBuilderType;

$builder->add('conditions', QueryBuilderType::class, [
    'fields' => [
        new Field(name: 'email', label: 'Email'),
        new Field(name: 'total_orders', type: ValueType::Number, label: 'Total orders'),
    ],
]);
```

Render it like any other field (`form_row(form.conditions)`). The type registers five options on top of the usual ones:

| Option                     | Type                              | Default                            | Purpose                                                     |
|----------------------------|-----------------------------------|------------------------------------|-------------------------------------------------------------|
| `fields`                   | `Field[]` or plain arrays         | `[]`                               | The fields the user can build rules on                      |
| `operators`                | `Operator[]` or strings           | `null`                             | The operators offered in the rule editor, on the fields without a list of their own |
| `processor`                | `QueryBuilderProcessor` or string | `QueryBuilderProcessor::JsonLogic` | What the submitted value carries                            |
| `lang`                     | string                            | `%kernel.default_locale%`          | Language of the editor labels                               |
| `validate_condition_tree`  | bool                              | `true`                             | Refuse a submitted tree that steps outside the two options above (see [Security](#security)) |

Because the type is built on `HiddenType`, the common options (`label`, `mapped`, `constraints`, ...) work as usual, and `empty_data` defaults to `'{}'`. The type overrides one inherited default: `invalid_message`, which would otherwise read "The hidden field is invalid." for a widget the user can see.

Two things to know on the data side:

- The model data is `array|null`: a mapped property must accept both. An empty or cleared builder submits as `null`.
- The controller fires `input` and `change` events on the hidden input whenever the query changes, so dirty-state trackers see the edits.

#### Changing the options after render

The controller reads `fields`, `operators`, `lang` and `processor` when it connects. A page where they depend on another input of the same form (a context or entity type select, a data source) changes them by writing the matching `data-query-builder-*-value` attribute of the widget's outer `<div>`, the one carrying `data-controller="query-builder"`. From another Stimulus controller, for instance:

```js
this.editorTarget.dataset.queryBuilderFieldsValue = JSON.stringify(fields);
this.editorTarget.dataset.queryBuilderOperatorsValue = JSON.stringify(['=', 'in', 'notIn']);
this.editorTarget.dataset.queryBuilderLangValue = 'en';
this.editorTarget.dataset.queryBuilderProcessorValue = 'native';
```

`fields` is a list with the shape `Field::toArray()` produces (`name`, `type`, `label`, `labelInformation`, `values`, `operators`); `operators` is a list of operator names, and an empty one restores the defaults. A page that knows every possible list in advance renders them once, server side, and picks one on change. The form that handles the submission has to be built with the same options, or the server-side check refuses the tree (see [Security](#security)).

The editor is rebuilt with the new values and the query as it currently is, not as it was at page load: the rules typed since then are kept. Rules on a field the new list no longer declares, or whose operator their field no longer offers, are dropped, and a group left empty with them. A `valuesList` rule whose field no longer offers that operator, or no longer declares the value it holds, becomes the equality it is stored as when the field offers `=`: the value stays, in a text input, as it would on reopening. The hidden input is then rewritten through the active processor, with the usual `input` and `change` events, so the form never submits a tree the server would refuse for a field or an operator it does not declare. Asking the user to confirm a change that drops rules is the host's decision, taken before writing the attribute. Writing an attribute with the value the editor already has does nothing.

`processor` changes nothing in the editor: the tree it holds is stored again in the new format, without waiting for an edit. A form reopened on a value saved under another processor is migrated that way.

### The `fields` option

Each entry describes one field the user can build rules on:

```php
new Field(
    name: 'created_at',          // identifier used in the output tree (required)
    type: ValueType::Date,       // drives the editor and the operators, defaults to ValueType::Text
    label: 'Creation date',      // shown in the field select, defaults to the name
    labelInformation: 'UTC',     // appended to the label in parentheses
    values: null,                // fixed list of FieldOption, see below
    operators: null,             // its own list of operators, see Per-field operators below
);
```

Plain arrays with the same keys are accepted and normalized to the DTO:

```php
['name' => 'created_at', 'type' => 'date', 'label' => 'Creation date']
```

The option is validated when the form is built: a missing or empty `name`, a duplicated `name`, an unknown `type` or an invalid `operators` list (see [Per-field operators](#per-field-operators)) throws an `InvalidOptionsException`. In the editor, the fields are sorted alphabetically by label.

#### The `Field.values`

`values` adds a "List of values" operator rendered as a select. Each entry is a `FieldOption`:

```php
use OpenStudio\QueryBuilderBundle\Dto\FieldOption;

new Field(
    name: 'status',
    label: 'Status',
    values: [
        new FieldOption(name: 'draft'),
        new FieldOption(name: 'published', label: 'Published'),
        new FieldOption(name: 'archived', label: 'Archived', value: '3'),
    ],
);
```

A `FieldOption` carries three properties:

- `name` (required) identifies the option. It doubles as the label and as the submitted value when the other two properties are not set.
- `label` is the text shown in the select. Defaults to `name`.
- `value`, when set, replaces `name` as the value written into the output tree. Use it when what the user reads differs from what the application stores: enum codes, database identifiers.

**If `name` and `value` differ for a given option, `value` takes precedence.**

Picking "Archived" in the example above stores `{"==": [{"var": "status"}, "3"]}` in the tree, while the select displayed "Archived".

As with `Field`, plain entries are accepted and normalized to the DTO — either associative arrays with the same keys, or `'name' => 'label'` string pairs:

```php
values: [
    ['name' => 'published', 'label' => 'Published'],
    ['name' => 'archived', 'label' => 'Archived', 'value' => '3'],
],
// or
values: ['draft' => 'Draft', 'published' => 'Published'],
```

An entry of any other shape throws an `InvalidOptionsException` when the form is built. An empty list means the field has no list of values at all: it is normalized to `null`, and the "List of values" operator is not offered rather than opening a select with nothing to pick.

### Value types

The `ValueType` enum decides which value editor the user gets and which operators are offered:

| `ValueType`      | Value editor | Operators                                                                      |
|------------------|--------------|--------------------------------------------------------------------------------|
| `Text` (default) | text input   | `=`, `null`, `contains`, `doesNotContain`, `beginsWith`, `endsWith`, `in`, `regex`, `notRegex` |
| `Number`         | number input | `=`, `null`, `>`, `>=`, `<`, `<=`                                              |
| `Date`           | date picker  | `=`, `null`, `>`, `>=`, `<`, `<=`, `<=ndays`, `>=ndays`                        |
| `Boolean`        | checkbox     | `=`, `null`                                                                    |

The enum is exhaustive: a `type` outside it throws an `InvalidOptionsException` when the form is built, listing the accepted values. There is no separate date-and-time type — a `Date` field sends a plain `YYYY-MM-DD` value. On a column that stores a time as well, that is worth knowing when you build the query: `datediff` (the `ndays` operators) ignores the time part, while an exact equality compares against midnight.

Each operator label in the UI also shows its technical name in parentheses. Behaviors that do not show in the table:

- `in` and `notIn` use a textarea with one value per line. The stored list is comma-separated, whatever the processor.
- `<=ndays` and `>=ndays` compare the age of a date field in days from today. In parameterized SQL they become `datediff(curdate(), field)` comparisons; the native tree keeps the operator name and the number of days.
- On a `Number` field, `null` ("is empty") matches both `NULL` and `0`, in the `jsonLogic` and `parameterized` processors: the tree holds an inclusion in `[null, 0]`, and the SQL fragment is `field is null or field = 0`. `notNull` ("is not empty") is its exact complement — `field is not null and field <> 0` — so a `0` never satisfies both. The native tree carries the operator as picked (`null`, `notNull`) and leaves that choice to the application.
- On a field with `values`, the extra "List of values" operator outputs a plain equality. Reopening the query gives the rule that operator back, and its select, as long as the stored value is still one of the field's declared values — otherwise it comes back as a plain `=`.
- `regex` and `notRegex` are stored as a delimited pattern (`/typed/iu`, case-insensitive and Unicode), in the `jsonLogic` and `parameterized` processors, because the tree is evaluated as JsonLogic; the native tree is not, and holds the pattern as typed. A slash in the pattern is fine: it is escaped in the stored form, so `^\d+/\d+$` stays one compilable pattern instead of ending at its own slash. Reopening the query undoes all of it, so the editor shows the pattern as it was typed; a value that already reads as one well-formed `/pattern/flags` is taken as delimited by hand and kept as-is, in both directions.
- The SQL fragments generated for `regex`, `notRegex` and the `ndays` operators use MySQL/MariaDB functions (`regexp`, `datediff`, `curdate`). The pattern is bound without its delimiters there, as `REGEXP` expects.

### Operators

The operator sets in the table above are the defaults. The `operators` option, `null` by default, replaces them with a single list applied to the whole widget, using the `Operator` enum:

```php
use OpenStudio\QueryBuilderBundle\Enum\Operator;

$builder->add('conditions', QueryBuilderType::class, [
    'fields' => $fields,
    'operators' => [Operator::Equal, Operator::NotEqual, Operator::Contains, Operator::Null],
]);
```

The backing strings are accepted as well and normalized to the enum:

```php
'operators' => ['=', '!=', 'contains', 'null'],
```

The full list:

| Enum case                          | String value     | Description                                                        |
|------------------------------------|------------------|--------------------------------------------------------------------|
| `Operator::Equal`                  | `=`              | Is equal to                                                        |
| `Operator::NotEqual`               | `!=`             | Is not equal to                                                    |
| `Operator::LessThan`               | `<`              | Is less than                                                       |
| `Operator::GreaterThan`            | `>`              | Is greater than                                                    |
| `Operator::LessThanOrEqual`        | `<=`             | Is less than or equal to                                           |
| `Operator::GreaterThanOrEqual`     | `>=`             | Is greater than or equal to                                        |
| `Operator::Between`                | `between`        | Is between two values (comma-separated, in a single input)         |
| `Operator::NotBetween`             | `notBetween`     | Is not between two values (comma-separated, in a single input)     |
| `Operator::Contains`               | `contains`       | Contains the substring                                             |
| `Operator::DoesNotContain`         | `doesNotContain` | Does not contain the substring                                     |
| `Operator::BeginsWith`             | `beginsWith`     | Begins with                                                        |
| `Operator::EndsWith`               | `endsWith`       | Ends with                                                          |
| `Operator::In`                     | `in`             | Matches one of the listed values (textarea, one value per line)    |
| `Operator::NotIn`                  | `notIn`          | Matches none of the listed values (textarea, one value per line)   |
| `Operator::Null`                   | `null`           | Is empty (on a `Number` field, matches both `NULL` and `0`)        |
| `Operator::NotNull`                | `notNull`        | Is not empty                                                       |
| `Operator::Regex`                  | `regex`          | Matches the regular expression                                     |
| `Operator::NotRegex`               | `notRegex`       | Does not match the regular expression                              |
| `Operator::LessThanOrEqualNDays`   | `<=ndays`        | The date is at most N days old, counted from today                 |
| `Operator::GreaterThanOrEqualNDays`| `>=ndays`        | The date is at least N days old, counted from today                |
| `Operator::ValuesList`             | `valuesList`     | Plain equality against the field's `values`, picked from a select  |

Three rules to keep in mind:

- The list is exhaustive: an operator left out is not offered, the automatic ones included. A field with `values` keeps its "List of values" operator only when `Operator::ValuesList` is listed, and text fields keep `regex`/`notRegex` only when listed.
- Field type compatibility still applies: `<`, `>`, `<=`, `>=` stay on `Number` and `Date` fields, `contains`, `doesNotContain`, `beginsWith`, `endsWith` and `in` on `Text` fields, `<=ndays`/`>=ndays` on `Date` fields, `valuesList` on fields with `values`. Listing an operator never forces it onto an incompatible field. A field with a list of its own is the exception, see below.
- The order of the list is the display order in the operator select.

A status field defined with `values` thus offers exactly two operators, "List of values" first, with:

```php
'operators' => [Operator::ValuesList, Operator::Null],
```

The option also unlocks operators that belong to no default set: `Operator::NotEqual` (`!=`), `Operator::NotIn` (`notIn`), `Operator::NotNull` (`notNull`), `Operator::Between` and `Operator::NotBetween` (both expect two values separated by a comma, in a single input).

An empty list, a duplicated operator, an unknown string or an entry that is neither an `Operator` nor a string throws an `InvalidOptionsException` when the form is built.

#### Per-field operators

The option is one list for the whole widget; from one field to the next, only the `ValueType` makes a difference. A field that needs its own set declares it with `Field.operators`:

```php
new Field(
    name: 'total_orders',
    type: ValueType::Number,
    operators: [Operator::Equal, Operator::In, Operator::NotIn],
),
```

The array shape takes it as well, as enum cases or backing strings: `'operators' => ['=', 'in', 'notIn']`. The list follows the rules of the option: an empty list, a repeated or an unknown operator throws an `InvalidOptionsException` when the form is built.

When set, the list is the exhaustive, ordered set of operators for that field and replaces the global list there, whether the option is `null` or not. It is taken as written: the operators were named for this very field, so the type compatibility rules above do not filter it — which is what lets a `Number` field offer `in` and `notIn`, or a `Text` field do without `regex`. The value editor still follows the operator (a textarea for `in`/`notIn`, a select for `valuesList`, a number input for the `ndays` operators), and `valuesList` is still only offered when the field declares `values`. Fields without the property behave as before, and the server-side check follows the same split (see [Security](#security)).

### Processor

The `processor` option decides what the hidden input carries, and is set with the `QueryBuilderProcessor` enum:

- `QueryBuilderProcessor::JsonLogic` (default): the JsonLogic tree only.
- `QueryBuilderProcessor::Parameterized`: an object holding the JsonLogic tree under `conditionTree` and the parameterized SQL under `parameterizedSql` (see [ParameterizedSQL](https://react-querybuilder.js.org/api/react-querybuilder/interfaces/ParameterizedSQL) in the React Query Builder API).
- `QueryBuilderProcessor::Native`: the tree as React Query Builder holds it (a [RuleGroupType](https://react-querybuilder.js.org/api/react-querybuilder/interfaces/RuleGroupType)) without the ids the editor adds: groups as `{combinator, not, rules}`, rules as `{field, operator, value}`. Not JsonLogic — the operator names are the editor's own — for an application that compiles the rules itself, see [Saving with the `native` processor](#saving-with-the-native-processor).

```php
use OpenStudio\QueryBuilderBundle\Enum\QueryBuilderProcessor;
use OpenStudio\QueryBuilderBundle\Form\QueryBuilderType;

$builder->add('conditions', QueryBuilderType::class, [
    'fields' => $fields,
    'processor' => QueryBuilderProcessor::Parameterized,
]);
```

The backing strings (`jsonLogic`, `parameterized`, `native`) are accepted as well and normalized to the enum.

The three processors agree on what an empty editor means: the field data is `null`, never a tree-shaped value to test for. In `parameterized` mode that also drops the SQL an empty query formats to, which is the neutral `(1 = 1)` — a statement matching every row, and the last thing a segment the user just emptied should carry. In `native` mode a group with no rule left in it is empty as well.

> ⚠️​ **Warning**: both parts are built in the browser and reach the server as untrusted input. Anyone can submit arbitrary SQL in `parameterizedSql`: never execute it as-is, treat it as a preview or debugging aid. `conditionTree` travels through the same hidden input; the type checks it against your `fields` and `operators`, but turning it into a query safely is still on you — see [Security](#security).

### Language

The editor ships its labels in English (`en`) and French (`fr`). Pick the language per field with the `lang` option:

```php
$builder->add('conditions', QueryBuilderType::class, [
    'lang' => 'en',
    // ...
]);
```

By default the bundle uses `kernel.default_locale`. Regional locales are reduced to their language part (`fr_FR` becomes `fr`), and a language without bundled labels falls back to English.

## Examples

A segment editor: users define who belongs to a marketing segment, and the application stores the conditions.

### The form

```php
// src/Form/SegmentType.php
use OpenStudio\QueryBuilderBundle\Dto\Field;
use OpenStudio\QueryBuilderBundle\Dto\FieldOption;
use OpenStudio\QueryBuilderBundle\Enum\ValueType;
use OpenStudio\QueryBuilderBundle\Form\QueryBuilderType;

final class SegmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('conditions', QueryBuilderType::class, [
                'fields' => [
                    new Field(name: 'email', label: 'Email'),
                    new Field(name: 'total_orders', type: ValueType::Number, label: 'Total orders'),
                    new Field(name: 'created_at', type: ValueType::Date, label: 'Sign-up date'),
                    new Field(name: 'newsletter', type: ValueType::Boolean, label: 'Newsletter opt-in'),
                    new Field(
                        name: 'status',
                        label: 'Status',
                        values: [
                            new FieldOption(name: 'active', label: 'Active'),
                            new FieldOption(name: 'inactive', label: 'Inactive'),
                            new FieldOption(name: 'banned', label: 'Banned'),
                        ],
                    ),
                ],
            ]);
    }
}
```

```twig
{{ form_start(form) }}
    {{ form_row(form.name) }}
    {{ form_row(form.conditions) }}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

### Saving with the `jsonLogic` processor (default)

The mapped property receives the JsonLogic tree as an array, so a Doctrine `json` column needs no extra work:

```php
#[Route('/segments/new', name: 'segment_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $entityManager): Response
{
    $segment = new Segment();
    $form = $this->createForm(SegmentType::class, $segment);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // $segment->getConditions() holds the JsonLogic tree as an array, or null when the
        // builder was left empty. It only mentions declared fields and operators: a tree that
        // stepped outside them made the form invalid (see the Security section).
        $entityManager->persist($segment);
        $entityManager->flush();

        return $this->redirectToRoute('segment_list');
    }

    return $this->render('segment/new.html.twig', ['form' => $form]);
}
```

For the query "status is active and email contains @example.com", the stored value is:

```json
{"and": [{"==": [{"var": "status"}, "active"]}, {"in": ["@example.com", {"var": "email"}]}]}
```

To apply the segment later, evaluate the tree with a JsonLogic implementation — after registering the operations listed in [Evaluating a saved tree](#evaluating-a-saved-tree), as a plain evaluator does not know all of them — or translate it into a query on the server. The tree only mentions fields and operators you declared, but see [Security](#security) before building SQL from it.

### Saving with the `parameterized` processor

Set the processor and unmap the field, then read the two parts in the controller:

```php
->add('conditions', QueryBuilderType::class, [
    'mapped' => false,
    'processor' => QueryBuilderProcessor::Parameterized,
    'fields' => [/* same fields as above */],
])
```

```php
if ($form->isSubmitted() && $form->isValid()) {
    // null when the builder was left empty, otherwise the two parts below.
    $data = $form->get('conditions')->getData();

    // The value to persist: the JsonLogic tree, already checked against the declared fields and
    // operators, see the Security section.
    $conditionTree = $data['conditionTree'] ?? null;
    $segment->setConditions(is_array($conditionTree) ? $conditionTree : null);

    // ['sql' => '...', 'params' => [...]], untrusted browser input: log it,
    // display it as a preview, but never execute it as-is.
    $parameterizedSql = $data['parameterizedSql'] ?? null;

    $entityManager->persist($segment);
    $entityManager->flush();

    return $this->redirectToRoute('segment_list');
}
```

For the same query, the submitted value looks like this:

```json
{
    "conditionTree": {"and": [{"==": [{"var": "status"}, "active"]}, {"in": ["@example.com", {"var": "email"}]}]},
    "parameterizedSql": {
        "sql": "(( status = ? ) and ( email like ? ))",
        "params": ["active", "%@example.com%"]
    }
}
```

### Saving with the `native` processor

The mapped property receives the tree as React Query Builder holds it, for an application that turns the rules into a query itself — a dictionary mapping field names to columns, a whitelist of operators, values bound as parameters:

```php
->add('conditions', QueryBuilderType::class, [
    'processor' => QueryBuilderProcessor::Native,
    'fields' => [/* same fields as above */],
])
```

For the same query, the stored value is:

```json
{
    "combinator": "and",
    "rules": [
        {"field": "status", "operator": "=", "value": "active"},
        {"field": "email", "operator": "contains", "value": "@example.com"}
    ]
}
```

A nested group is a rule holding `rules` of its own, with its `combinator` and, when the group is negated, `"not": true`. The ids and paths the editor keeps for itself are not stored. Three things differ from what the editor displays, and are undone when the query reopens: a "List of values" rule is stored as `=`, an `in`/`notIn` list is comma-separated, and a rule left without a field or an operator is left out, as is a group left without rules.

The operator names are those of the `Operator` enum, and the values are what the inputs hold: strings, `true`/`false` for a `Boolean` field, a comma-separated pair for `between`. Nothing is rewritten for evaluation — `regex` holds the pattern as typed, `null` on a `Number` field is the operator alone — so the section below does not apply: the tree is not JsonLogic.

### Evaluating a saved tree

With the `jsonLogic` and `parameterized` processors, the stored value is JsonLogic, but not only standard JsonLogic: seven operations come from react-querybuilder or from this bundle, and a plain evaluator raises `Unrecognized operation` on them. Register them before calling `apply()`.

| Operation  | Emitted for                   | Arguments, once `var` references are resolved | Meaning                                                    |
|------------|-------------------------------|-----------------------------------------------|------------------------------------------------------------|
| `startsWith` | `beginsWith`                | value, prefix                                 | the value starts with the prefix                           |
| `endsWith`   | `endsWith`                  | value, suffix                                 | the value ends with the suffix                             |
| `regex`      | `regex`                     | value, `/pattern/iu`                          | the value matches the pattern, delimiters and flags included |
| `notRegex`   | `notRegex`                  | value, `/pattern/iu`                          | the value does not match the pattern                       |
| `<=ndays`    | `<=ndays` on a date field   | date, number of days                          | the date is at most N days old, counted from today         |
| `>=ndays`    | `>=ndays` on a date field   | date, number of days                          | the date is at least N days old, counted from today        |
| `notNull`    | `notNull` on a number field | value, `[null, 0]`                            | the value is none of the listed empty values               |

Everything else the editor emits is standard and needs no registration: `and`, `or`, `!`, `==`, `!=`, `<`, `<=`, `>`, `>=` and `in`. That last one covers two cases — list membership for the `in` operator, and substring for `contains` — while `doesNotContain`, `notIn` and `notBetween` arrive wrapped in `!`, and `between` as a three-argument `<=`.

The bundle ships them, so there is nothing to transcribe. `register()` takes the registration callable of your evaluator, which keeps the bundle independent from any particular one:

```php
use JWadhams\JsonLogic;
use OpenStudio\QueryBuilderBundle\Service\JsonLogicOperations;

JsonLogicOperations::register(JsonLogic::add_operation(...));

$belongsToSegment = JsonLogic::apply($segment->getConditions(), $customer);
```

`JsonLogicOperations::all()` returns the same operations as a `name => callable` map, for an evaluator that registers them differently.

Values reach these callables from your own data, so they are narrowed rather than trusted: a mistyped or unfinished rule evaluates to `false` instead of raising a `TypeError` mid-evaluation.
Two consequences to know:

- An empty prefix, suffix or pattern matches nothing. `str_starts_with('anything', '')` is `true` in PHP, so an unfinished "begins with" rule would otherwise select every record.
- A pattern that does not compile is a false match, not a warning: the compilation notice is swallowed rather than sent to your logs.

> ⚠️​ **Warning**: the `regex` pattern is typed by the user, so it is untrusted input on two counts. An invalid pattern makes `preg_match()` emit a warning and return `false`, and a catastrophic one can hang the request through backtracking. Validate the pattern, or bound it — `pcre.backtrack_limit` and `pcre.jit` in PHP, a timeout in your runtime — before evaluating trees from untrusted authors.

If you translate the tree into SQL instead of evaluating it, these seven operations are the ones with no direct SQL equivalent; the `parameterized` processor shows the fragments this bundle uses for them (`regexp`, `datediff(curdate(), field)`, `is not null and <> 0`).

## Security

The widget writes the whole payload from the browser, so **everything the field submits is untrusted input** — the JsonLogic tree included. The `fields` and `operators` options constrain the editor, never what a crafted POST carries.

> ⚠️​ **Warning**: `parameterizedSql` is generated in the browser: anyone can submit arbitrary SQL in it. Never execute it as-is. Treat it as a preview or debugging aid. The bundle does not validate it.

### The submitted tree is validated

Whatever the options, a payload that is valid JSON but not a query object — a scalar, a list — is refused before anything else: the field data is an array or `null`, never a value that would surface as a type error in your entity.

On top of that, the type checks every submitted tree against the options you declared, and refuses it when:

- a `{"var": "..."}` names a field absent from the `fields` option, or is not a string;
- an operation key is not one the declared operators can produce — those of the `operators` option, or of a field's own `operators` list;
- an operation is applied to a field whose operators do not produce it: the field's own list when it has one, the `operators` option otherwise, so a list declared on one field opens nothing to another;
- a field reference carries anything besides its `var` key, which would smuggle an unchecked operation past the walk.

A tree from the `native` processor is checked rule by rule instead, and refused when a group's `combinator` is not `and` or `or`, its `not` is not a boolean, its `rules` is not a list, or a rule's `field` is not a declared name or its `operator` not one of the operators declared for that field — its own list, or the `operators` option (`valuesList` counting as the `=` it is stored as).

A refused tree becomes a regular form error — `The submitted query is invalid.` — and **never reaches your model**: the validation runs in the model transformer, so `$form->getData()` stays `null`. The message shown to the user never echoes the submitted payload back; the precise reason travels in the exception, server side.

The whitelist of operations is derived from the `operators` option through `Operator::jsonLogicOperations()`, which maps each operator to the JsonLogic keys the editor emits for it (`beginsWith` to `startsWith`, `contains` to `in`, `between` to a three-argument `<=`, `valuesList` to an equality, and the bundle's own `regex`, `notRegex`, `<=ndays`, `>=ndays`, plus `notNull` on a number field), and for a native tree through `Operator::nativeOperator()`. Leaving `operators` at `null` allows the keys of every operator; restricting the option restricts the trees the server accepts as well.

A field with its own `operators` widens that whitelist with what its list produces, so an operator the option leaves out is not refused on the field that declares it — and the field is held to that list alone: a `<` on a field restricted to `=`, `in` and `notIn` is refused where the same tree on an unrestricted `Number` field passes. The check keeps the granularity of the operation key: `contains` and `in` both produce an `in`, so a field allowing one accepts the other.

Turn it off with:

```php
$builder->add('conditions', QueryBuilderType::class, [
    'fields' => $fields,
    'validate_condition_tree' => false,
]);
```

What to know before you do:

- The option only turns off the check against your `fields` and `operators`. The payload is still refused unless it is a query object, so the field data stays an array or `null`.
- A tree stored **before** a field was renamed or removed from `fields` is refused on the next submit, instead of being silently re-saved against a field that no longer exists.
- The check covers field names and operations, not values. A value is still whatever the user typed.
- Trees written by an earlier version of this bundle may hold a raw operator key on a date field (`{"=": ...}`, `{"null": ...}`, `{"between": ...}`) instead of valid JsonLogic. The editor now emits `==`, `== null` and a three-argument `<=` for those, and such a legacy tree is refused: reopening the query in the editor and saving it again rewrites it.

### What remains yours

Validation says the tree only mentions fields you declared. It does not make the tree safe to concatenate: **field and table names cannot be bound as SQL parameters**, so map the validated names through a whitelist of your own — `['total_orders' => 'customer.total_orders']` — and bind the values as parameters:

```php
use OpenStudio\QueryBuilderBundle\Enum\Operator;
use OpenStudio\QueryBuilderBundle\Service\ConditionTreeValidator;

// Same guarantee outside a form: validating a tree read back from storage.
$validator = new ConditionTreeValidator(['email', 'total_orders'], Operator::cases());
$validator->assertValid($segment->getConditions());
```

`ConditionTreeValidator::assertValid()` accepts the three payload shapes (a JsonLogic tree, the `{conditionTree, parameterizedSql}` object of the `parameterized` processor, or the group of the `native` processor) and throws `ConditionTreeValidationException`. The constructor takes the declared field names, the operators of the option (every case when it is `null`) and, as an optional third argument, the per-field lists as a map of field name to operators: `['total_orders' => [Operator::Equal, Operator::In, Operator::NotIn]]`.

## Development

```bash
composer install
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/psalm
vendor/bin/phpstan analyse
```

CI runs the same three checks on every push and pull request.

## License

MIT License. See [LICENSE](LICENSE).
