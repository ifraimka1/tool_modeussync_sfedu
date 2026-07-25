<?php

namespace tool_modeussync;

class str_utils
{
    public static function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if (!$length) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }

    /** Добавляет слеш в конец строки, если необходимо */
    public static function ensureSlash($value)
    {
        if (str_utils::endsWith($value, '/')) {
            return "$value";
        }
        return "$value/";
    }
}
