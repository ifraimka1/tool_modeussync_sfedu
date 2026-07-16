<?php

namespace tool_modeussync\interop;


/** CURL, который посылает все запросы в версии HTTP 1.1. См. MODEUSSW-19250 */
class curl_http_version_1_1 extends \curl
{
    public function resetopt()
    {
        parent::resetopt();
        parent::setopt(['CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1]);
    }
}
