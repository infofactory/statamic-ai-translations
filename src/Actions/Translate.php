<?php

namespace Infofactory\StatamicAiTranslations\Actions;

use Locale;
use Statamic\Facades\Site;
use Statamic\Fields\Field;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Statamic\Fields\Fields;
use Statamic\Actions\Action;
use Statamic\Fieldtypes\Bard;
use Prism\Prism\Facades\Prism;
use Statamic\Fields\Blueprint;
use Illuminate\Support\Collection;
use Statamic\Fieldtypes\Bard\Augmentor;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Handles AI-powered translation of Statamic content
 *
 * This class provides functionality to automatically translate content in Statamic
 * using AI (via Prism service) while maintaining the structure of complex fields.
 */
class Translate extends Action
{

    /**
     * Recursively scans through all fields to identify which ones are translatable
     *
     * @param Collection $fields Collection of Field objects to check
     * @param bool|null $rootIsLocalizable Whether the root field is localizable
     * @return Collection Nested collection of field configurations that can be translated
     */
    private function getTranslatableFields(Collection $fields, bool | null $rootIsLocalizable = null): Collection
    {
        $fieldsData = collect();

        // Define which field types contain text that can be translated
        $textFieldTypes = collect(['text', 'code', 'markdown', 'textarea', 'slug', 'array', 'list', 'table', 'taggable', 'bard']);
        // Define field types that can contain other fields
        $containerFieldTypes = collect(['grid', 'group']);

        /** @var Field $field */
        foreach ($fields as $field) {

            $isLocalizable = $rootIsLocalizable ?? $field->isLocalizable();

            // Handle container fields (grid, group) that can contain other fields
            if ($containerFieldTypes->contains($field->type())) {
                $fieldConfig = $field->config();
                $fieldFields = new Fields($fieldConfig['fields']);
                $containerTranslatableFields = $this->getTranslatableFields($fieldFields->resolveFields(), $isLocalizable);
                if (!$containerTranslatableFields->isEmpty()) {
                    $fieldsData->put($field->handle(), collect([
                        'field' => $field,
                        'fields' => $containerTranslatableFields,
                    ]));
                }
                continue;
            }

            // Handle replicator fields and Bard fields with sets (complex content structures)
            if ($field->type() === 'replicator' || ($field->type() === 'bard' && isset($field->config()['sets']))) {
                $replicatorSets = collect();
                foreach ($field->config()['sets'] as $setGroup) {
                    foreach ($setGroup['sets'] as $setHandle => $set) {
                        $setFields = new Fields($set['fields']);
                        // Fix: Keep only the already existing set handles
                        if ($replicatorSets->has($setHandle)) {
                            continue;
                        }
                        $replicatorSets->put($setHandle, $this->getTranslatableFields($setFields->resolveFields(), $isLocalizable));
                    }
                }
                if (!$replicatorSets->isEmpty()) {
                    $fieldsData->put($field->handle(), collect([
                        'field' => $field,
                        'sets' => $replicatorSets,
                    ]));
                }
                continue;
            }

            // Handle regular text-based fields that are marked as localizable
            if ($textFieldTypes->contains($field->type()) && $isLocalizable) {
                $fieldsData->put($field->handle(), collect([
                    'field' => $field,
                ]));
                continue;
            }
        }

        return $fieldsData;
    }

    /**
     * Determines which field handles need translation by comparing with existing translations
     *
     * @param Collection $translatableFields All fields that can be translated
     * @param Collection $translatedFields Fields that already have translations
     * @return Collection Field handles that need translation
     */
    private function getHandlesToTranslate(Collection $translatableFields, Collection $translatedFields): Collection
    {
        $translatableHandles = $translatableFields->keys();
        $translatedHandles = $translatedFields->only($translatableHandles->toArray())->keys();
        return $translatableHandles->diff($translatedHandles);
    }

    /**
     * Main method to translate a set of fields based on their type
     *
     * @param array $origin Original content to translate from
     * @param array $item Existing translated content (to avoid retranslating)
     * @param Collection $translatableFields Collection of fields that can be translated
     * @param Collection $handlesToTranslate Specific field handles that need translation
     * @param string $targetLocaleName Target language/locale for translation
     * @return array Translated field values
     */
    private function translateFields(array $origin, array $item, Collection $translatableFields, Collection $handlesToTranslate, string $targetLocaleName)
    {
        $result = [];
        foreach ($handlesToTranslate as $fieldHandle) {
            $fieldData = $translatableFields[$fieldHandle];
            /** @var Field $field */
            $field = $fieldData->get('field');
            $originalValue = collect($origin)->get($fieldHandle);
            $originalItem = collect($item)->get($fieldHandle, []);
            if (is_null($originalValue)) continue;

            $value = null;

            // Route to the appropriate translation method based on field type
            if ($field->type() === 'group') {
                $value = $this->translateGroup($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'grid') {
                $value = $this->translateGrid($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'array') {
                $value = $this->translateArray($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'list') {
                $value = $this->translateList($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'table') {
                $value = $this->translateTable($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'taggable') {
                $value = $this->translateList($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'replicator') {
                $value = $this->translateReplicator($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else if ($field->type() === 'bard') {
                $value = $this->translateBard($originalValue, $originalItem, $targetLocaleName, $fieldData);
            } else {
                // Default translation for simple text fields
                $value = $this->translateValue($originalValue, $targetLocaleName);
            }

            $result[$fieldHandle] = $value;
        }

        return $result;
    }

    private function translateGroup(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $handlesToTranslate = $this->getHandlesToTranslate($fieldData['fields'], collect($item));
        return array_merge($origin, $this->translateFields($origin, $item, $fieldData['fields'], $handlesToTranslate, $targetLocaleName));
    }

    private function translateGrid(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $result = [];
        foreach ($origin as $data) {
            $translatedGridElement = $this->translateGroup($data, $item, $targetLocaleName, $fieldData);
            $translatedGridElement['id'] = $data['id'] ?? Str::uuid()->toString();
            $result[] = $translatedGridElement;
        }
        return $result;
    }

    private function translateArray(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $handlesToTranslate = $this->getHandlesToTranslate(collect($origin), collect($item));
        $result = [];
        foreach ($handlesToTranslate as $fieldHandle) {
            $value = collect($origin)->get($fieldHandle);
            $result[$fieldHandle] = $this->translateValue($value, $targetLocaleName);
        }
        return $result;
    }

    private function translateList(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $result = [];
        foreach ($origin as $value) {
            $result[] = $this->translateValue($value, $targetLocaleName);
        }
        return $result;
    }

    private function translateTable(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $result = [];
        foreach ($origin as $row) {
            $newRow = [
                'cells' => []
            ];
            foreach ($row['cells'] as $col) {
                $newRow['cells'][] = $this->translateValue($col, $targetLocaleName);
            }
            $result[] = $newRow;
        }
        return $result;
    }


    private function translateSet(array $set, array $item, $targetLocaleName, Collection $fieldData): array
    {
        $setType = $set['type'];
        $setFields = $fieldData['sets'][$setType];
        $setResult = $this->translateGroup($set, $item, $targetLocaleName, collect(['fields' => $setFields]));
        $setResult['type'] = $setType;
        $setResult['id'] = $set['id'] ?? Str::uuid()->toString();
        $setResult['enabled'] = $set['enabled'] ?? true;
        return $setResult;
    }

    private function translateReplicator(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $result = [];
        foreach ($origin as $set) {
            $result[] = $this->translateSet($set, $item, $targetLocaleName, $fieldData);
        }
        return $result;
    }

    private function translateBard(array $origin, array $item, $targetLocaleName, Collection $fieldData)
    {
        $field = $fieldData->get('field');
        $augmentor = new Augmentor((new Bard)->setField($field));
        $augmentedValue = $augmentor->augment($origin);
        if (is_array($augmentedValue)) {
            $translatedValues = [];
            foreach ($augmentedValue as $values) {
                if ($values->type === 'text') {
                    $translation = $this->translateValue($values->text, $targetLocaleName);
                    $proseTranslation = $augmentor->renderHtmlToProsemirror($translation)['content'];
                    $translatedValues = array_merge($translatedValues, $proseTranslation);
                } else {
                    $raw_values = [
                        'id' => $values->id ?? Str::uuid()->toString(),
                        'type' => $values->type,
                    ];
                    collect($values->toArray())->each(function ($value, $key) use (&$raw_values) {
                        if (array_key_exists($key, $raw_values)) {
                            return;
                        }
                        $raw_values[$key] = $value->raw();
                    });
                    $translatedSet = $this->translateSet($raw_values, $item, $targetLocaleName, $fieldData);
                    $setData = [
                        'type' => 'set',
                        'attrs' => [
                            'id' => $translatedSet['id'],
                            'values' => [],
                        ],
                    ];
                    unset($translatedSet['id']);
                    $setData['attrs']['values'] = $translatedSet;
                    $translatedValues[] = $setData;
                }
            }
            return $translatedValues;
        } else {
            $translation = $this->translateValue($augmentedValue, $targetLocaleName);
            return $translation;
        }
    }

    /**
     * Translates a single text value using the configured AI translation service
     *
     * @param string|null $value The text to translate
     * @param string $targetLocaleName The target language/locale for translation
     * @return string|null The translated text or null if input was null
     */
    private function translateValue(?string $value, $targetLocaleName): ?string
    {
        if (is_null($value)) {
            return null;
        }

        // Build the system prompt with translation instructions
        $systemPrompt = 'You are a translator that translates text from one language to another. '.
            'Keep the structure intact. Only translate the text. '.
            'Reply with just the translated text or HTML without any wrapper of any kind.';

        // Add any custom instructions from the config
        if (config('statamic-ai-translations.instructions')) {
            $systemPrompt .= "\n\n#Style Instructions  \n".config('statamic-ai-translations.instructions');
        }

        // Add the target language to the prompt
        $systemPrompt .= "\n\nTranslate the following text to $targetLocaleName.";

        // Call the AI translation service (Prism)
        $response = Prism::text()
            ->using(
                config('statamic-ai-translations.provider'),
                config('statamic-ai-translations.model')
            )
            ->withSystemPrompt($systemPrompt)
            ->withMessages([new UserMessage($value)])
            ->asText();

        // Add a small delay to prevent hitting rate limits
        usleep(500000); // 500ms

        return $response->text;
    }

    /**
     * Main entry point for the translation action
     *
     * This method is called when the translation action is triggered in the Statamic control panel.
     * It handles the entire translation process, including:
     * 1. Validating the item has an origin
     * 2. Determining the target locale
     * 3. Identifying translatable fields
     * 4. Translating the content
     * 5. Saving the translated content back to the item
     *
     * @param mixed $items Collection of items to translate (typically contains one item)
     * @param array $values Additional values from the action form (unused in this implementation)
     * @return void
     * @throws \Exception If the item doesn't have an origin
     */
    public function run($items, $values)
    {
        // Get the first (and typically only) item to translate
        $item = $items->first();
        $hasOrigin = $item->hasOrigin();

        // Ensure the item has an origin to translate from
        if (! $hasOrigin) {
            throw new \Exception('This action is only available for items with an origin.');
        }

        // Determine the target language/locale for translation
        $targetSite = Site::get($item->locale());
        $targetLocale = $targetSite->locale();
        $targetLocaleName = Locale::getDisplayName($targetLocale, 'en_US');

        // Get the original content to translate from
        $origin = $item->origin();

        // Get the blueprint to understand the field structure
        /** @var Blueprint $blueprint */
        $blueprint = $item->blueprint();

        // Find all translatable fields and which ones need translation
        $translatableFields = $this->getTranslatableFields($blueprint->fields()->resolveFields());
        $handlesToTranslate = $this->getHandlesToTranslate($translatableFields, $item->data());

        // Get the original data to translate
        $originData = $origin->data()->toArray();

        // Get the current data
        $currentData = $item->data()->toArray();

        // Translate the translatable
        $translatedData = $this->translateFields($originData, $currentData, $translatableFields, $handlesToTranslate, $targetLocaleName);

        // Merge existing translations with newly translated content
        $itemData = array_merge(
            $currentData,
            $translatedData,
        );

        // Save the translated content back to the item
        $item->data($itemData);
        $item->save();

        return [
            'message' => false,
            'callback' => ['reloadPage'],
        ];
    }

    /**
     * Checks if the translation service is properly configured
     *
     * @return bool True if both provider and model are configured
     */
    private function isCorrectlySetup()
    {
        return config('statamic-ai-translations.provider') && config('statamic-ai-translations.model');
    }

    /**
     * Determines if the translation action should be visible for a given item
     *
     * @param mixed $item The item to check
     * @return bool True if the action should be visible for this item
     */
    public function visibleTo($item)
    {
        $isEntry = $item instanceof Entry;
        $isCorrectlySetup = $this->isCorrectlySetup();

        return $isEntry && $isCorrectlySetup;
    }
}
