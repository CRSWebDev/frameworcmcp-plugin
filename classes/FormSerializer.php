<?php namespace CRSCompany\FrameworCMcp\Classes;

use Tailor\Models\EntryRecord;

/**
 * FormSerializer renders Form entries as MCP JSON.
 */
class FormSerializer extends ContentSerializer
{
    /**
     * summary is the shape used by the form index.
     */
    public static function summary(EntryRecord $form): array
    {
        return [
            'id' => (int) $form->id,
            'title' => $form->title,
            'slug' => $form->slug,
            'is_enabled' => (bool) $form->is_enabled,
            'site_id' => $form->site_id !== null ? (int) $form->site_id : null,
            'field_count' => $form->fwcFields()->count(),
            'updated_at' => optional($form->updated_at)->toAtomString(),
        ];
    }

    /**
     * full includes the scalar fields and the field rows.
     */
    public static function full(EntryRecord $form): array
    {
        $schema = FormWriter::schema();

        return static::summary($form) + [
            'headline' => $form->headline,
            'recipients' => static::castOut($form->recipients, $schema['recipients'] ?? ['type' => 'taglist']),
            'fwcFields' => static::groupedRows($form->fwcFields, FormWriter::fieldsSpec()),
        ];
    }
}
