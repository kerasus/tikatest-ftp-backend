<?php

namespace App\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;
use Normalizer;

class FileNameService
{
    /**
     * حداکثر طول نام فایل بدون extension
     */
    private const MAX_FILENAME_LENGTH = 120;

    /**
     * extension های خطرناک
     */
    private const BLOCKED_EXTENSIONS = [
        'php',
        'php3',
        'php4',
        'php5',
        'phtml',
        'phar',
        'exe',
        'sh',
        'bash',
        'bat',
        'cmd',
        'msi',
        'com',
        'js',
        'jar',
    ];

    /**
     * نرمال‌سازی کامل نام فایل
     */
    public function normalize(
        string $filename,
        string $directory,
        Filesystem|FilesystemAdapter $storage
    ): string {
        $filename = $this->normalizeUnicode($filename);

        $extension = $this->extractExtension($filename);

        $this->validateExtension($extension);

        $name = $this->extractName($filename);

        $name = $this->normalizeSpaces($name);

        $name = $this->removeDangerousCharacters($name);

        $name = $this->limitLength($name);

        $name = $this->fallbackIfEmpty($name);

        $extension = $this->sanitizeExtension($extension);

        $finalName = $this->buildFilename($name, $extension);

        $finalName = $this->makeUnique(
            $finalName,
            $directory,
            $storage
        );

        return $finalName;
    }

    /**
     * Unicode normalization (NFC)
     */
    private function normalizeUnicode(string $value): string
    {
        if (!class_exists(Normalizer::class)) {
            return $value;
        }

        return Normalizer::normalize(
            $value,
            Normalizer::FORM_C
        );
    }

    /**
     * گرفتن extension
     */
    private function extractExtension(string $filename): string
    {
        return strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );
    }

    /**
     * گرفتن نام فایل بدون extension
     */
    private function extractName(string $filename): string
    {
        return pathinfo(
            $filename,
            PATHINFO_FILENAME
        );
    }

    /**
     * نرمال‌سازی فاصله‌ها
     */
    private function normalizeSpaces(string $value): string
    {
        // unicode spaces -> normal space
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * حذف کاراکترهای خطرناک
     */
    private function removeDangerousCharacters(string $value): string
    {
        return preg_replace(
            '/[^آ-یa-zA-Z0-9\s\-_()\[\]]/u',
            '',
            $value
        );
    }

    /**
     * محدود کردن طول نام فایل
     */
    private function limitLength(string $value): string
    {
        return Str::limit(
            $value,
            self::MAX_FILENAME_LENGTH,
            ''
        );
    }

    /**
     * اگر نام فایل خالی شد
     */
    private function fallbackIfEmpty(string $value): string
    {
        return empty($value)
            ? 'file'
            : $value;
    }

    /**
     * تمیز کردن extension
     */
    private function sanitizeExtension(string $extension): string
    {
        return preg_replace(
            '/[^a-zA-Z0-9]/',
            '',
            strtolower($extension)
        );
    }

    /**
     * ساخت filename نهایی
     */
    private function buildFilename(
        string $name,
        string $extension
    ): string {
        return $extension
            ? "{$name}.{$extension}"
            : $name;
    }

    /**
     * جلوگیری از executable upload
     */
    private function validateExtension(string $extension): void
    {
        if (
            in_array(
                strtolower($extension),
                self::BLOCKED_EXTENSIONS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'File extension is not allowed.'
            );
        }
    }

    /**
     * جلوگیری از نام تکراری
     */
    private function makeUnique(
        string $filename,
        string $directory,
        Filesystem|FilesystemAdapter $storage
    ): string {
        $extension = pathinfo(
            $filename,
            PATHINFO_EXTENSION
        );

        $name = pathinfo(
            $filename,
            PATHINFO_FILENAME
        );

        $counter = 1;

        $candidate = $filename;

        while (
        $storage->exists(
            trim($directory, '/') . '/' . $candidate
        )
        ) {

            $candidate = $extension
                ? "{$name} ({$counter}).{$extension}"
                : "{$name} ({$counter})";

            $counter++;
        }

        return $candidate;
    }
}
