<?php

    namespace Statikbe\FilamentFlexibleContentBlocks\ContentBlocks\Concerns;

    use Statikbe\FilamentFlexibleContentBlocks\Filament\Form\Fields\Blocks\ImageWidthField;

    trait HasImageWidth {
        public ?string $imageWidth;

        public function getImageWidth(): ?string {
            return ImageWidthField::getImageWidthClass($this->imageWidth);
        }
    }