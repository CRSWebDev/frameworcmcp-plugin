<?php namespace CRSCompany\FrameworCMcp\Classes;

use DB;
use Str;
use Tailor\Models\EntryRecord;

/**
 * FormWriter creates and edits Form entries including their field rows.
 *
 * A form's fields live in the fwcFields grouped repeater: every row names its
 * group (the field type) and carries the flattened BaseFormField mixin.
 */
class FormWriter extends ContentWriter
{
    /**
     * @var array entryFields writable attributes on the form record.
     */
    protected static $entryFields = ['title', 'headline', 'recipients'];

    /**
     * schema returns the walked Form blueprint.
     */
    public static function schema(): array
    {
        return BlockSchema::forBlueprint('Form');
    }

    /**
     * fieldsSpec returns the fwcFields grouped-repeater spec.
     */
    public static function fieldsSpec(): array
    {
        $spec = static::schema()['fwcFields'] ?? null;

        if (!$spec || empty($spec['grouped'])) {
            throw new ApiException('The Form blueprint has no grouped fwcFields repeater.', 500);
        }

        return $spec;
    }

    /**
     * create writes a new form.
     */
    public static function create(array $payload): EntryRecord
    {
        $formData = (array) ($payload['form'] ?? []);
        $rows = $payload['fwcFields'] ?? null;

        SchemaGuard::assertReady('Form');

        if (trim((string) ($formData['title'] ?? '')) === '') {
            throw ApiException::invalid(['form.title' => 'This field is required.']);
        }

        static::validateForm($formData);

        if ($rows !== null) {
            static::validateRows((array) $rows);
        }

        return DB::transaction(function () use ($formData, $rows) {
            $form = EntryRecord::inSection('Form');

            static::fillForm($form, $formData);
            $form->slug = trim((string) ($formData['slug'] ?? '')) !== ''
                ? $formData['slug']
                : Str::slug($formData['title']);
            $form->is_enabled = (bool) ($formData['is_enabled'] ?? true);
            $form->save();

            if ($rows) {
                static::writeGroupedRows($form, 'fwcFields', (array) $rows, static::fieldsSpec());
            }

            return $form;
        });
    }

    /**
     * update edits form attributes and optionally rebuilds the field rows.
     *
     * The rows carry no media, so a full rebuild loses nothing.
     */
    public static function update(EntryRecord $form, array $payload): EntryRecord
    {
        $formData = (array) ($payload['form'] ?? []);
        $rows = $payload['fwcFields'] ?? null;

        SchemaGuard::assertReady('Form');

        static::validateForm($formData);

        if ($rows !== null) {
            static::validateRows((array) $rows);
        }

        return DB::transaction(function () use ($form, $formData, $rows) {
            if ($formData) {
                static::fillForm($form, $formData);

                if (array_key_exists('slug', $formData)) {
                    $form->slug = $formData['slug'];
                }
                if (array_key_exists('is_enabled', $formData)) {
                    $form->is_enabled = (bool) $formData['is_enabled'];
                }

                $form->save();
            }

            if ($rows !== null) {
                foreach ($form->fwcFields as $existing) {
                    $existing->delete();
                }

                $form->reloadRelations('fwcFields');

                static::writeGroupedRows($form, 'fwcFields', (array) $rows, static::fieldsSpec());
            }

            return $form;
        });
    }

    /**
     * fillForm assigns the writable scalar attributes.
     */
    protected static function fillForm(EntryRecord $form, array $data): void
    {
        $schema = static::schema();

        foreach (static::$entryFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $form->{$field} = static::castIn($data[$field], $schema[$field] ?? []);
        }
    }

    /**
     * validateForm checks the form record payload.
     */
    protected static function validateForm(array $data): void
    {
        $errors = [];

        $known = array_merge(static::$entryFields, ['slug', 'is_enabled']);
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $known, true)) {
                $errors['form.' . $key] = 'Unknown field. Allowed: ' . implode(', ', $known) . '.';
            }
        }

        foreach ((array) ($data['recipients'] ?? []) as $i => $recipient) {
            if (!filter_var((string) $recipient, FILTER_VALIDATE_EMAIL)) {
                $errors['form.recipients.' . $i] = '"' . $recipient . '" is not a valid e-mail address.';
            }
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }
    }

    /**
     * validateRows checks the fwcFields payload beyond the generic group rules.
     */
    protected static function validateRows(array $rows): void
    {
        $errors = [];
        $spec = static::fieldsSpec();

        static::validateGroupedRows($rows, $spec, 'fwcFields', $errors);

        // Input groups post their value under `name`; without one the field
        // never reaches the inquiry. `section` is presentational.
        $seen = [];
        foreach (array_values($rows) as $i => $row) {
            $row = (array) $row;
            $group = $row['group'] ?? null;

            if (!$group || !isset($spec['groups'][$group]) || $group === 'section') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $errors['fwcFields.' . $i . '.name'] = 'This field is required for input fields.';
                continue;
            }

            if (isset($seen[$name])) {
                $errors['fwcFields.' . $i . '.name'] = 'Duplicate field name "' . $name . '" (also used at index ' . $seen[$name] . ').';
                continue;
            }

            $seen[$name] = $i;

            // Choice groups need at least one option to render.
            if (in_array($group, ['select', 'checkbox', 'radio'], true)) {
                $options = (array) ($row['options'] ?? []);

                if (!$options) {
                    $errors['fwcFields.' . $i . '.options'] = 'At least one option is required.';
                    continue;
                }

                foreach (array_values($options) as $j => $option) {
                    $option = (array) $option;

                    if (trim((string) ($option['value'] ?? '')) === '') {
                        $errors['fwcFields.' . $i . '.options.' . $j . '.value'] = 'This field is required.';
                    }
                }
            }
        }

        if ($errors) {
            throw ApiException::invalid($errors);
        }
    }
}
