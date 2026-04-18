<?php

$string['pluginname'] = 'Modeus Sync Plugin';
$string['auto_publish_as_lti_tools'] = 'Publish new courses and modules as LTI tools';
$string['manage'] = 'Custis LTI Extensions';
$string['start_lti_sync'] = 'Start sync of membership and grades with LTI platforms';
$string['push_courses'] = 'Send info to LTI platforms about courses and their modules';
$string['pull_courses'] = 'Create new courses using data from LTI platforms';
$string['sync_user'] = 'The user who starts the LTI synchronization';
$string['sync_user_descr'] = 'This user will be added to the course as a teacher to start syncing via LTI. After working out the sync_members job, the user will be removed from the course';
$string['auto_link_users'] = 'Register Moodle users in LTI';
$string['provisioningmode'] = 'Provisioning mode. WARNING! Value changes don\'t affect existing LTI Tools, only new ones';
$string['platform_settings'] = 'Additional settings for convenient integration via LTI';
$string['platform_settings_descr'] = 'JSON format (it is important to use double quotes): { "deploymentid": { "lmsapi": "url" }}';
$string['common'] = 'Common settings of LTI extensions';
$string['lang'] = 'Language to publish LTI tool';
$string['lang_descr'] = 'Use only languages installed in Moodle. WARNING! Value changes don\'t affect existing LTI Tools, only new ones';
$string['lti_field_user_id'] = 'The field where the user ID for LTI is stored';
$string['lti_field_user_id_descr'] = 'This field will be used to synchronize the list of users and their grades with the LTI Platform. Attention! Changing this field will only bind new users correctly. Old users will remain tied to the old ID';
$string['default_category'] = 'Default category for created courses';
$string['default_category_descr'] = 'Courses created based on data from the Platform will be placed in this category';
