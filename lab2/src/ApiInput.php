<?php
declare(strict_types=1);

namespace Lab2;

final class ApiInput
{
    public static function invalid(string $field, string $message): never
    {
        throw new ApiException(422, 'validation_error', 'Parametros invalidos.', [$field => $message]);
    }

    public static function integer(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || strlen($value) > strlen((string) $max)
            || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)
            || (int) $value < $min) {
            self::invalid($field, "Debe ser entero entre $min y $max.");
        }
        return (int) $value;
    }

    public static function query(array $allowed): array
    {
        foreach (array_keys($_GET) as $key) {
            if (!in_array($key, $allowed, true)) { self::invalid((string) $key, 'Parametro desconocido.'); }
        }
        return $_GET;
    }

    public static function pagination(array $query): array
    {
        return [self::integer($query['limit'] ?? '25', 'limit', 1, 100),
            self::integer($query['offset'] ?? '0', 'offset', 0, 1000000)];
    }

    public static function dates(array $query): array
    {
        $from = isset($query['from']) ? self::integer($query['from'], 'from', 0, 253402300799) : null;
        $to = isset($query['to']) ? self::integer($query['to'], 'to', 0, 253402300799) : null;
        if ($from !== null && $to !== null && $from >= $to) {
            self::invalid('to', 'Debe ser mayor que from (intervalo [from,to)).');
        }
        return [$from, $to];
    }

    public static function configuration(): array
    {
        self::query([]);
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw === false || strlen($raw) > 16384) { self::invalid('body', 'Maximo 16384 bytes.'); }
        try { $payload = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'malformed_json', 'JSON malformado o demasiado profundo.'); }
        if (!$payload instanceof \stdClass) { self::invalid('body', 'Debe ser un objeto JSON.'); }
        $fields = get_object_vars($payload);
        if (array_diff(array_keys($fields), ['key', 'value'])) { self::invalid('body', 'Solo se admiten key y value.'); }
        if (!isset($fields['key']) || !is_string($fields['key'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $fields['key'])) {
            self::invalid('key', 'De 1 a 64 caracteres alfanumericos, guion o subrayado.');
        }
        if (!array_key_exists('value', $fields)) { self::invalid('value', 'Campo obligatorio (null es un valor JSON valido).'); }
        if (!self::jsonTree($fields['value'])) { self::invalid('value', 'JSON finito con profundidad maxima 8.'); }
        $json = json_encode($fields['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 4096) { self::invalid('value', 'Maximo 4096 bytes JSON.'); }
        return [$fields['key'], $json];
    }

    private static function jsonTree(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) { return false; }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $child) { if (!self::jsonTree($child, $depth + 1)) { return false; } }
        }
        return !is_float($value) || is_finite($value);
    }
}
