<?php

namespace tool_modeussync;

class courses_consts
{
    /** Эти типы элементов не умеем создавать */
    public static $unsupported_module_types = array("h5pactivity", "scorm", "imscp");

    /** Технические типы, которые не являются элементами РМУП и не экспортируются. */
    public static $non_exportable_module_types = array('modeussync');
}
