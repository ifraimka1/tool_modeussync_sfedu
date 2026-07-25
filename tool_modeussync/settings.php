<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $common_settings = new admin_settingpage('common', 'Общие настройки');
    $users_settings = new admin_settingpage('users_sync', 'Настройки синхронизации пользователей');
    $courses_settings = new admin_settingpage('courses_sync', 'Настройки синхронизации курсов');
    $grades_settings = new admin_settingpage('grades_sync', 'Настройки синхронизации оценок');

    $safe_interval_description = 'Дополнительный временной интервал изменений, 
    захватываемый при каждой синхронизации, для исключения возможности потери изменений на “стыке” сессий синхронизации';
    $session_interval_description = 'Максимальный временной интервал синхронизируемых изменений';

    if ($ADMIN->fulltree) {
        $connection_config = new admin_setting_configtextarea(
            'tool_modeussync/connection_settings',
            'Конфигурация подключений',
            'JSON формат (важно использовать двойные кавычки). Пример: { "deploymentid": { "adapterUrl": "https://modeus.org/lms-adapter/" }}',
            "",
            PARAM_RAW
        );
        $common_settings->add($connection_config);

        require_once __DIR__ . '/settings/admin_setting_user_fields.php';
        $users_settings->add(new admin_setting_user_fields());
        $users_settings->add(
            new admin_setting_configtext(
                'tool_modeussync/users_safe_interval_minutes',
                $safe_interval_description,
                'Число в минутах',
                "5",
                PARAM_INT,
                "20"
            )
        );
        $users_settings->add(
            new admin_setting_configcheckbox(
                'tool_modeussync/unenrol_students',
                'Исключать студентов с курсов при синхронизации',
                '',
                "1"
            )
        );
        $users_settings->add(
            new admin_setting_configcheckbox(
                'tool_modeussync/unenrol_teachers',
                'Исключать преподавателей с курсов при синхронизации',
                '',
                "1"
            )
        );
        $users_settings->add(
            new admin_setting_configcheckbox(
                'tool_modeussync/allow_person_user_duplicates',
                'Выполнять зачисление персон, у которых есть несколько пользователей в Moodle (будет взят первый идентификатор по возрастанию)',
                '',
                "0"
            )
        );

        $courses_settings->add(
            new admin_settings_coursecat_select(
                'tool_modeussync/default_category',
                'Категория по-умолчанию для создаваемых курсов',
                'Курсы, создаваемые по данным из Modeus, будут помещаться в эту категорию',
                1
            )
        );
        $courses_settings->add(
            new admin_setting_configtext(
                'tool_modeussync/courses_max_interval_minutes',
                $session_interval_description,
                'Число в минутах',
                "525600",
                PARAM_INT,
                "20"
            )
        );
        $courses_settings->add(
            new admin_setting_configtext(
                'tool_modeussync/courses_safe_interval_minutes',
                $safe_interval_description,
                'Число, в минутах',
                "5",
                PARAM_INT,
                "20"
            )
        );
        $courses_settings->add(new admin_setting_configtext(
            'tool_modeussync/syncservice_base_url',
            get_string('syncservice_base_url', 'tool_modeussync'),
            get_string('syncservice_base_url_desc', 'tool_modeussync'),
            '',
            PARAM_URL
        ));
        $courses_settings->add(new admin_setting_configpasswordunmask(
            'tool_modeussync/internal_api_key',
            get_string('internal_api_key', 'tool_modeussync'),
            get_string('internal_api_key_desc', 'tool_modeussync'),
            ''
        ));

        $grades_settings->add(
            new admin_setting_configtext(
                'tool_modeussync/grades_max_interval_minutes',
                $session_interval_description,
                'Число в минутах',
                "40320",
                PARAM_INT,
                "20"
            )
        );
        $grades_settings->add(
            new admin_setting_configtext(
                'tool_modeussync/grades_safe_interval_minutes',
                $safe_interval_description,
                'Число, в минутах',
                "5",
                PARAM_INT,
                "20"
            )
        );
    }

    $ADMIN->add('tools', new admin_category('managemodeussync', 'Modeus Sync Plugin'));
    $ADMIN->add('managemodeussync', $common_settings);
    $ADMIN->add('managemodeussync', $users_settings);
    $ADMIN->add('managemodeussync', $courses_settings);
    $ADMIN->add('managemodeussync', $grades_settings);
}
