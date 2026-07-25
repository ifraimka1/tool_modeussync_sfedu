<?php

namespace tool_modeussync;

use Throwable;

class debug_utils
{
    /** Нужно ли показывать отладочную информацию? */
    public static function shouldShowDebugInfo()
    {
        global $CFG;
        $hasdebugdeveloper = (isset($CFG->debugdisplay) &&
            isset($CFG->debug) &&
            $CFG->debugdisplay &&
            ($CFG->debug === DEBUG_DEVELOPER || $CFG->debug === DEBUG_ALL)
        );

        return $hasdebugdeveloper;
    }

    public static function traceError(Throwable $e)
    {
        mtrace($e->getMessage());
        if (debug_utils::shouldShowDebugInfo()) {
            mtrace($e->getTraceAsString());
        }
    }
}
