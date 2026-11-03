<?php

namespace App\Modules\Recettes\Services;

use GdImage;
use RuntimeException;

/**
 * Prépare une image de plat pour la base : redressée (photos de téléphone), réduite
 * et réencodée en WebP (JPEG à défaut), ce qui la nettoie aussi de tout contenu indésirable.
 */
class ImageProcessor
{
    /**
     * @return array{mime: string, binary: string}
     */
    public function process(string $binary): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Extension PHP GD absente : impossible de traiter l\'image.');
        }

        $image = @getimagesizefromstring($binary) === false ? false : @imagecreatefromstring($binary);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Fichier image illisible.');
        }

        $image = $this->resize($this->orient($image, $binary), (int) config('recettes.image.max_width'));

        return $this->encode($image, (int) config('recettes.image.quality'));
    }

    /** Applique l'orientation EXIF des photos JPEG (sinon une photo prise en portrait s'affiche couchée). */
    private function orient(GdImage $image, string $binary): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($binary, "\xFF\xD8")) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function resize(GdImage $image, int $maxWidth): GdImage
    {
        if (imagesx($image) <= $maxWidth) {
            return $image;
        }

        return imagescale($image, $maxWidth, -1, IMG_BICUBIC) ?: $image;
    }

    /**
     * @return array{mime: string, binary: string}
     */
    private function encode(GdImage $image, int $quality): array
    {
        $webp = function_exists('imagewebp');

        ob_start();
        $webp ? imagewebp($image, null, $quality) : imagejpeg($image, null, $quality);

        return ['mime' => $webp ? 'image/webp' : 'image/jpeg', 'binary' => (string) ob_get_clean()];
    }
}
