<?php namespace CRSCompany\FrameworCMcp\Classes;

use CRSCompany\FrameworC\Models\FrameworcSetting;
use Yaml;

/**
 * SettingsBridge exposes the FrameworC settings page over the API.
 *
 * The whole settings blob lives under one `wrapper` key. The Integrace tab
 * holds secrets (captcha and webhook credentials), so every `integration_*`
 * or `sensitive` field is invisible here: absent from the schema, absent from
 * reads, and a write attempt gets the same generic unknown-field error as any
 * made-up key — the API never confirms those fields exist. Writes merge into
 * the stored wrapper so the hidden values survive byte-for-byte.
 */
class SettingsBridge
{
    /**
     * @var array deniedPrefixes hidden from schema, read and write.
     */
    protected static $deniedPrefixes = ['integration_'];

    /**
     * @var array presentational types that never carry a value.
     */
    protected static $ignoredTypes = ['section', 'hint', 'partial', 'ruler'];

    /**
     * @var array|null schema memoised per request.
     */
    protected static $schema = null;

    /**
     * schema derives the exposed fields from the settings fields.yaml.
     */
    public static function schema(): array
    {
        if (static::$schema !== null) {
            return static::$schema;
        }

        $path = plugins_path('crscompany/frameworc/models/frameworcsetting/fields.yaml');

        if (!file_exists($path)) {
            throw new ApiException('The FrameworC settings definition is missing. Is the FrameworC plugin installed?', 500);
        }

        $config = (array) Yaml::parseFile($path);
        $fields = (array) (
            $config['fields']['wrapper']['form']['tabs']['fields']
            ?? $config['fields']['wrapper']['form']['fields']
            ?? []
        );

        $out = [];

        foreach ($fields as $name => $field) {
            $field = (array) $field;
            $type = $field['type'] ?? 'text';

            if (in_array($type, static::$ignoredTypes, true)) {
                continue;
            }

            if ($type === 'sensitive' || static::isDenied($name)) {
                continue;
            }

            $spec = ['type' => $type];

            foreach (['label', 'default', 'comment', 'tab'] as $carry) {
                if (isset($field[$carry])) {
                    $spec[$carry] = $field[$carry];
                }
            }

            if (!empty($field['options'])) {
                $spec['options'] = (array) $field['options'];
            }

            $out[$name] = $spec;
        }

        return static::$schema = $out;
    }

    /**
     * read returns the exposed settings values plus their schema.
     */
    public static function read(): array
    {
        $wrapper = (array) FrameworcSetting::instance()->wrapper;
        $schema = static::schema();

        $fields = [];

        foreach ($schema as $name => $spec) {
            $fields[$name] = array_key_exists($name, $wrapper)
                ? $wrapper[$name]
                : ($spec['default'] ?? null);
        }

        return [
            'scope' => 'global',
            'fields' => $fields,
            'schema' => $schema,
        ];
    }

    /**
     * write merges allowed values into the stored wrapper.
     */
    public static function write(array $fields): array
    {
        $schema = static::schema();
        $errors = [];
        $coerced = [];

        foreach ($fields as $name => $value) {
            if (!isset($schema[$name])) {
                $errors['fields.' . $name] = 'Unknown field. Allowed: ' . implode(', ', array_keys($schema)) . '.';
                continue;
            }

            $spec = $schema[$name];

            if (!empty($spec['options'])
                && $value !== null && $value !== ''
                && !in_array((string) $value, array_map('strval', array_keys($spec['options'])), true)) {
                $errors['fields.' . $name] = 'Invalid value "' . $value . '". Allowed: '
                    . implode(', ', array_keys($spec['options'])) . '.';
                continue;
            }

            // Validate the value's type and coerce it to the stored shape, so a
            // JSON string/number landing in a switch or list cannot be merged
            // into the wrapper as the wrong type. Null/empty clears the field.
            $checked = static::coerceValue($value, $spec, $invalidValue);

            if ($invalidValue !== null) {
                $errors['fields.' . $name] = $invalidValue;
                continue;
            }

            $coerced[$name] = $checked;
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }

        // Merge into the full stored wrapper so every hidden key survives
        // untouched.
        $wrapper = (array) FrameworcSetting::instance()->wrapper;

        foreach ($coerced as $name => $value) {
            $wrapper[$name] = $value;
        }

        FrameworcSetting::set('wrapper', $wrapper);
        FrameworcSetting::clearInternalCache();

        return static::read();
    }

    /**
     * coerceValue validates the value's type and casts it to the stored shape.
     *
     * Returns the cast value, or null when the input is empty. A null return
     * paired with a non-empty input signals a type mismatch so the caller can
     * report it as a validation error rather than silently storing null.
     */
    protected static function coerceValue($value, array $spec, ?string &$invalidValue)
    {
        $invalidValue = null;
        $type = $spec['type'] ?? 'text';

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if ($type === 'switch') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (in_array($type, ['taglist', 'checkboxlist'], true)) {
            $list = is_array($value) ? array_values($value) : [$value];
            $out = [];

            foreach ($list as $single) {
                if (is_scalar($single)) {
                    $out[] = (string) $single;
                    continue;
                }

                $invalidValue = 'Expected an array of strings.';
                return null;
            }

            return $out;
        }

        if ($type === 'number') {
            if (!is_numeric($value)) {
                $invalidValue = 'Expected a number.';
                return null;
            }

            return (float) $value;
        }

        if (in_array($type, ['mediafinder', 'fileupload'], true)) {
            $invalidValue = 'Media fields cannot be set over the API.';
            return null;
        }

        if (!is_scalar($value)) {
            $invalidValue = 'Expected a scalar value.';
            return null;
        }

        return $value;
    }

    /**
     * isDenied hides secret-bearing keys.
     */
    protected static function isDenied(string $name): bool
    {
        foreach (static::$deniedPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
