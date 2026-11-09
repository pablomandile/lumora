<?php

namespace App\Services\Images;

use InvalidArgumentException;

/**
 * Reemplaza los metadatos de texto de un PNG.
 *
 * ComfyUI guarda el workflow completo en chunks tEXt ("prompt" y
 * "workflow"); se sacan y se dejan solo los datos útiles para el usuario
 * (prompt, seed, modelo) en chunks iTXt, que admiten UTF-8.
 */
final class PngMetadata
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const TEXT_CHUNKS = ['tEXt', 'zTXt', 'iTXt'];

    public static function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, self::SIGNATURE);
    }

    /**
     * @param  array<string, string>  $texts  clave (1–79 caracteres latin-1) => texto UTF-8
     */
    public static function replaceText(string $png, array $texts): string
    {
        if (! self::isPng($png)) {
            throw new InvalidArgumentException('No es un PNG.');
        }

        $out = self::SIGNATURE;
        $offset = strlen(self::SIGNATURE);
        $length = strlen($png);

        while ($offset + 12 <= $length) {
            $size = self::chunkSize($png, $offset);
            $type = substr($png, $offset + 4, 4);
            $chunk = substr($png, $offset, 12 + $size);
            $offset += 12 + $size;

            if (in_array($type, self::TEXT_CHUNKS, true)) {
                continue;
            }

            if ($type === 'IEND') {
                foreach ($texts as $key => $text) {
                    $out .= self::chunk('iTXt', $key."\0\0\0\0\0".$text);
                }
            }

            $out .= $chunk;

            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function readText(string $png): array
    {
        $texts = [];
        $offset = strlen(self::SIGNATURE);

        while ($offset + 12 <= strlen($png)) {
            $size = self::chunkSize($png, $offset);
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $size);
            $offset += 12 + $size;

            if ($type === 'tEXt') {
                [$key, $text] = explode("\0", $data, 2) + [1 => ''];
                $texts[$key] = $text;
            } elseif ($type === 'iTXt') {
                // clave \0 flag método idioma \0 clave traducida \0 texto
                [$key, $rest] = explode("\0", $data, 2) + [1 => ''];
                $parts = explode("\0", substr($rest, 2), 3);
                $texts[$key] = $parts[2] ?? '';
            }
        }

        return $texts;
    }

    private static function chunkSize(string $png, int $offset): int
    {
        $unpacked = unpack('N', substr($png, $offset, 4));

        if ($unpacked === false) {
            throw new InvalidArgumentException('PNG dañado.');
        }

        return (int) $unpacked[1];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
