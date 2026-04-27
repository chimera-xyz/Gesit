<?php

namespace App\Support;

use ZipArchive;

class AndroidApkMetadataReader
{
    private const STRING_POOL_TYPE = 0x0001;
    private const START_ELEMENT_TYPE = 0x0102;
    private const UTF8_FLAG = 0x00000100;
    private const TYPE_STRING = 0x03;
    private const TYPE_INT_DEC = 0x10;
    private const ANDROID_VERSION_CODE = 0x0101021b;
    private const ANDROID_VERSION_NAME = 0x0101021c;

    /**
     * @return array{package_name?:string,version_name?:string,version_code?:int}
     */
    public function read(string $apkPath): array
    {
        if (! is_file($apkPath) || ! class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($apkPath) !== true) {
            return [];
        }

        try {
            $manifest = $zip->getFromName('AndroidManifest.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($manifest) || strlen($manifest) < 16) {
            return [];
        }

        return $this->readManifest($manifest);
    }

    /**
     * @return array{package_name?:string,version_name?:string,version_code?:int}
     */
    private function readManifest(string $binaryXml): array
    {
        $offset = 8;
        $strings = [];
        $resourceIds = [];
        $length = strlen($binaryXml);

        while ($offset + 8 <= $length) {
            $chunkType = $this->u16($binaryXml, $offset);
            $headerSize = $this->u16($binaryXml, $offset + 2);
            $chunkSize = $this->u32($binaryXml, $offset + 4);

            if ($chunkSize <= 0 || $offset + $chunkSize > $length) {
                break;
            }

            if ($chunkType === self::STRING_POOL_TYPE) {
                $strings = $this->readStringPool($binaryXml, $offset);
            } elseif ($chunkType === 0x0180) {
                $resourceIds = $this->readResourceMap($binaryXml, $offset, $chunkSize);
            } elseif ($chunkType === self::START_ELEMENT_TYPE && $headerSize >= 36) {
                $tagName = $this->stringAt($strings, $this->u32($binaryXml, $offset + 20));
                if ($tagName === 'manifest') {
                    return $this->readManifestAttributes($binaryXml, $offset, $strings, $resourceIds);
                }
            }

            $offset += $chunkSize;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function readStringPool(string $binaryXml, int $offset): array
    {
        $stringCount = $this->u32($binaryXml, $offset + 8);
        $flags = $this->u32($binaryXml, $offset + 16);
        $stringsStart = $this->u32($binaryXml, $offset + 20);
        $isUtf8 = ($flags & self::UTF8_FLAG) !== 0;
        $strings = [];

        for ($index = 0; $index < $stringCount; $index++) {
            $stringOffset = $this->u32($binaryXml, $offset + 28 + ($index * 4));
            $absoluteOffset = $offset + $stringsStart + $stringOffset;
            $strings[] = $isUtf8
                ? $this->readUtf8String($binaryXml, $absoluteOffset)
                : $this->readUtf16String($binaryXml, $absoluteOffset);
        }

        return $strings;
    }

    /**
     * @return array<int,int>
     */
    private function readResourceMap(string $binaryXml, int $offset, int $chunkSize): array
    {
        $ids = [];
        $count = intdiv(max(0, $chunkSize - 8), 4);

        for ($index = 0; $index < $count; $index++) {
            $ids[$index] = $this->u32($binaryXml, $offset + 8 + ($index * 4));
        }

        return $ids;
    }

    /**
     * @param list<string> $strings
     * @param array<int,int> $resourceIds
     * @return array{package_name?:string,version_name?:string,version_code?:int}
     */
    private function readManifestAttributes(
        string $binaryXml,
        int $offset,
        array $strings,
        array $resourceIds,
    ): array {
        $attributeStart = $this->u16($binaryXml, $offset + 24);
        $attributeSize = $this->u16($binaryXml, $offset + 26);
        $attributeCount = $this->u16($binaryXml, $offset + 28);
        $cursor = $offset + $attributeStart;
        $metadata = [];

        for ($index = 0; $index < $attributeCount; $index++) {
            $attributeOffset = $cursor + ($index * $attributeSize);
            $nameIndex = $this->u32($binaryXml, $attributeOffset + 4);
            $rawValueIndex = $this->u32($binaryXml, $attributeOffset + 8);
            $dataType = ord($binaryXml[$attributeOffset + 15] ?? "\0");
            $data = $this->u32($binaryXml, $attributeOffset + 16);
            $name = $this->stringAt($strings, $nameIndex);
            $resourceId = $resourceIds[$nameIndex] ?? null;

            if ($name === 'package') {
                $metadata['package_name'] = $this->readAttributeValue(
                    $strings,
                    $rawValueIndex,
                    $dataType,
                    $data,
                );
                continue;
            }

            if ($resourceId === self::ANDROID_VERSION_CODE || $name === 'versionCode') {
                $metadata['version_code'] = $dataType === self::TYPE_STRING
                    ? (int) $this->readAttributeValue($strings, $rawValueIndex, $dataType, $data)
                    : (int) $data;
                continue;
            }

            if ($resourceId === self::ANDROID_VERSION_NAME || $name === 'versionName') {
                $metadata['version_name'] = $this->readAttributeValue(
                    $strings,
                    $rawValueIndex,
                    $dataType,
                    $data,
                );
            }
        }

        return array_filter(
            $metadata,
            fn ($value) => $value !== null && $value !== '' && $value !== 0,
        );
    }

    /**
     * @param list<string> $strings
     */
    private function readAttributeValue(array $strings, int $rawValueIndex, int $dataType, int $data): ?string
    {
        if ($rawValueIndex !== 0xffffffff) {
            return $this->stringAt($strings, $rawValueIndex);
        }

        if ($dataType === self::TYPE_STRING) {
            return $this->stringAt($strings, $data);
        }

        if ($dataType === self::TYPE_INT_DEC) {
            return (string) $data;
        }

        return null;
    }

    /**
     * @param list<string> $strings
     */
    private function stringAt(array $strings, int $index): ?string
    {
        return $index >= 0 && array_key_exists($index, $strings) ? $strings[$index] : null;
    }

    private function readUtf8String(string $binaryXml, int $offset): string
    {
        [, $offset] = $this->readLength8($binaryXml, $offset);
        [$byteLength, $offset] = $this->readLength8($binaryXml, $offset);

        return substr($binaryXml, $offset, $byteLength);
    }

    private function readUtf16String(string $binaryXml, int $offset): string
    {
        [$charLength, $offset] = $this->readLength16($binaryXml, $offset);
        $raw = substr($binaryXml, $offset, $charLength * 2);
        $decoded = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * @return array{0:int,1:int}
     */
    private function readLength8(string $binaryXml, int $offset): array
    {
        $first = ord($binaryXml[$offset] ?? "\0");
        $offset += 1;

        if (($first & 0x80) !== 0) {
            $second = ord($binaryXml[$offset] ?? "\0");
            $offset += 1;

            return [(($first & 0x7f) << 8) | $second, $offset];
        }

        return [$first, $offset];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function readLength16(string $binaryXml, int $offset): array
    {
        $first = $this->u16($binaryXml, $offset);
        $offset += 2;

        if (($first & 0x8000) !== 0) {
            $second = $this->u16($binaryXml, $offset);
            $offset += 2;

            return [(($first & 0x7fff) << 16) | $second, $offset];
        }

        return [$first, $offset];
    }

    private function u16(string $value, int $offset): int
    {
        $unpacked = unpack('v', substr($value, $offset, 2));

        return (int) ($unpacked[1] ?? 0);
    }

    private function u32(string $value, int $offset): int
    {
        $unpacked = unpack('V', substr($value, $offset, 4));

        return (int) ($unpacked[1] ?? 0);
    }
}
