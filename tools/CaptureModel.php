<?php
declare(strict_types=1);

namespace Tablo\Tools;

require_once dirname(__DIR__) . '/app/RequiredFile.php';

/**
 * Consumed fields of the reviewed, checked-in Lekalo dashboard model.
 * @phpstan-type ModelType array{ref?: string, optional?: ModelType, list?: ModelType, ...}
 * @phpstan-type ModelField array{name: string, type: ModelType, required?: bool, ...}
 * @phpstan-type Definition array{id: string, kind: string, input?: list<ModelField>, returns?: ModelType,
 * reads?: list<string>, effects?: list<string>, operation?: string, entity?: string, fields?: list<ModelField>, ...}
 */
final class CaptureModel
{
    /** @return array<string, Definition> */
    public static function definitions(string $root): array
    {
        $files = glob($root . '/lekalo/modules/dashboard/*.yaml');
        if ($files === false) { throw new \RuntimeException('Cannot discover dashboard model files.'); }
        $definitions = [];
        foreach ($files as $file) {
            /** @var array{definitions: list<Definition>, ...} $model */
            $model = json_decode(\Tablo\RequiredFile::bytes(file_get_contents($file)), associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
            foreach ($model['definitions'] as $definition) { $definitions[$definition['id']] = $definition; }
        }
        return $definitions;
    }

    /** @param ModelType $type */
    public static function typeName(array $type): string
    {
        if (($type['ref'] ?? null) !== null) { return $type['ref']; }
        if (($type['optional'] ?? null) !== null) { return 'optional(' . self::typeName($type['optional']) . ')'; }
        if (($type['list'] ?? null) !== null) { return 'list(' . self::typeName($type['list']) . ')'; }
        throw new \RuntimeException('Unmapped type');
    }

    /**
     * @param ModelField $field
     * @return array{name: string, type: string, required: bool}
     */
    public static function field(array $field): array
    {
        return ['name' => $field['name'], 'type' => self::typeName($field['type']), 'required' => $field['required'] ?? false];
    }
}
