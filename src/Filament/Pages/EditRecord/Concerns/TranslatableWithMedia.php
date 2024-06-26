<?php

namespace Statikbe\FilamentFlexibleContentBlocks\Filament\Pages\EditRecord\Concerns;

use Filament\Resources\Pages\EditRecord\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statikbe\FilamentFlexibleContentBlocks\Filament\Form\Fields\ContentBlocksField;
use Statikbe\FilamentFlexibleContentBlocks\Models\Contracts\HasTranslatableMedia;

trait TranslatableWithMedia
{
    use Translatable;

    /**
     * Temporary overwrite until Filament is fixed.
     * {@inheritDoc}
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $translatableAttributes = static::getResource()::getTranslatableAttributes();

        $record->fill(Arr::except($data, $translatableAttributes));

        foreach (Arr::only($data, $translatableAttributes) as $key => $value) {
            $record->setTranslation($key, $this->activeLocale, $value);
        }

        $originalData = $this->data;

        $existingLocales = null;

        foreach ($this->otherLocaleData as $locale => $localeData) {
            $existingLocales ??= collect($translatableAttributes)
                ->map(fn (string $attribute): array => array_keys($record->getTranslations($attribute)))
                ->flatten()
                ->unique()
                ->all();

            //CHANGE
            $this->form->fill([
                ...$this->data,
                ...$localeData,
            ]);

            try {
                $this->form->validate();
            } catch (ValidationException $exception) {
                if (! array_key_exists($locale, $existingLocales)) {
                    continue;
                }

                $this->setActiveLocale($locale);

                throw $exception;
            }

            //$dehydratedLocaleData = $this->form->dehydrateState($localeData);
            //CHANGE:
            $localeData = Arr::only($this->form->getState(), array_keys($localeData));

            $localeData = $this->mutateFormDataBeforeSave($localeData);

            foreach (Arr::only($localeData, $translatableAttributes) as $key => $value) {
                $record->setTranslation($key, $locale, $value);
            }
        }

        $this->data = $originalData;

        $record->save();

        return $record;
    }

    /**
     * This function is overridden to be able to update the state of translatable media when the form locale switches.
     * It makes use of the getTranslatableMediaCollections() function to include these media collections as translatable
     * and thus change the state when the locale switches.
     *
     * @see HasTranslatableMedia
     */
    public function updatedActiveLocale(): void
    {
        if (blank($this->oldActiveLocale)) {
            return;
        }

        $this->resetValidation();

        $translatableAttributes = $this->getTranslatedAttributesWithTranslatableMedia();
        $emptyTranslatableMedia = Arr::mapWithKeys($this->getRecord()->getTranslatableMediaCollections(), function (string $item, int $key) {
            return [$item => []];
        });
        $translatableMedia = array_merge($emptyTranslatableMedia, $this->getRecord()->getTranslatableMediaUuidsPerMediaCollection($this->activeLocale));

        $this->otherLocaleData[$this->oldActiveLocale] = Arr::only($this->data, $translatableAttributes);

        $this->form->fill([
            ...$translatableMedia,
            ...Arr::except($this->data, $translatableAttributes),
            ...$this->otherLocaleData[$this->activeLocale] ?? [],
        ]);

        //handle images in content blocks field:
        /*if (isset($this->data[ContentBlocksField::FIELD])) {
            $this->data[ContentBlocksField::FIELD] = $this->transformContentBlocksImagesToArray($this->data[ContentBlocksField::FIELD]);
        }*/

        unset($this->otherLocaleData[$this->activeLocale]);
    }

    private function getTranslatedAttributesWithTranslatableMedia(): array
    {
        $translatableAttributes = app(static::getModel())->getTranslatableAttributes();
        if (method_exists($this->getRecord(), 'getTranslatableMediaCollections')) {
            $translatableAttributes = array_merge($translatableAttributes, $this->getRecord()->getTranslatableMediaCollections());
        } else {
            throw new \BadMethodCallException('The model does not implement the HasTranslatableMedia interface. If this is not implemented translated images will not change when the language switch changes.');
        }

        return $translatableAttributes;
    }

    //TODO remove double code from other TranslatableWithMedia:
    private function transformContentBlocksImagesToArray(array $contentBlocks): array
    {
        $transformedBlocks = [];
        foreach ($contentBlocks as $key => &$contentBlock) {
            if (is_array($contentBlock)) {
                if (is_int($key)) {
                    $key = Str::uuid()->toString();
                }
                $transformedBlocks[$key] = $this->transformOneContentBlocksImagesToArray($contentBlock);
            }
        }

        return $transformedBlocks;
    }

    private function transformOneContentBlocksImagesToArray(array &$contentBlock): array
    {
        $dataBlock = &$contentBlock;
        if (isset($contentBlock['data'])) {
            $dataBlock = &$contentBlock['data'];
        }

        //TODO configure image fields
        $fields = ['image'];
        foreach ($fields as $field) {
            if (isset($dataBlock[$field]) && ! is_array($dataBlock[$field])) {
                //put file fields in an array:
                $dataBlock[$field] = [$dataBlock[$field] => $dataBlock[$field]];
            }
        }

        foreach ($dataBlock as $var => $data) {
            if (is_array($data) && ! in_array($var, $fields)) {
                $dataBlock[$var] = $this->transformOneContentBlocksImagesToArray($data);
            }
        }

        return $contentBlock;
    }
}
