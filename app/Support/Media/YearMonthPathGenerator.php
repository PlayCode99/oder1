<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class YearMonthPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    private function basePath(Media $media): string
    {
        $modelType = str(class_basename($media->model_type))->snake()->toString();
        $timestamp = $media->created_at ?? now();

        return sprintf('%s/%s/%s/%d', $modelType, $timestamp->format('Y'), $timestamp->format('m'), $media->id);
    }
}
