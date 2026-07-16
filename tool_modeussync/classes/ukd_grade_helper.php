<?php
// File: admin/tool/modeussync/classes/ukd_grade_helper.php

namespace tool_modeussync;

use core_plugin_manager;
use context_system;
use stored_file;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for reading UKD table and extracting max grade
 */
class ukd_grade_helper {

    /** Column names in the UKD table */
    const COLUMN_COURSE_NAME = 'РМУП Название';
    const COLUMN_ASSIGNMENT_NAME = 'Встреча Название';
    const COLUMN_MAX_GRADE = 'MAX балл за встречу (M)';

    /**
     * Get max grade for a course/assignment pair from uploaded UKD table
     *
     * @param string $coursefullname Full name of the course
     * @param string $assignmentname Name of the assignment
     * @return int|null Max grade if found, null otherwise
     */
    public static function get_max_grade(string $coursefullname, string $assignmentname): ?int {
        $file = self::get_ukd_file();
        if (!$file) {
            mtrace('[DEBUG] no file');
            return null;
        }

        $spreadsheet = self::load_spreadsheet($file);
        if (!$spreadsheet) {
            mtrace('[DEBUG] no spreadsheet');
            return null;
        }

        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, true); // Preserve empty cells

        if (empty($rows)) {
            mtrace('[DEBUG] no rows');
            return null;
        }

        mtrace("[UKD] Searching for: Course='{$coursefullname}', Assignment='{$assignmentname}'");

        // Find header row
$headerRow = self::find_header_row($rows);
if ($headerRow === null) {
    mtrace('[UKD] Header row not found in first 10 rows');
    // Выведем первые строки для анализа
    for ($i = 1; $i <= min(5, count($rows)); $i++) {
        mtrace('[UKD] Row ' . $i . ': ' . json_encode($rows[$i], JSON_UNESCAPED_UNICODE));
    }
    return null;
}
mtrace("[UKD] Header found at row: {$headerRow}");

$headers = array_map('trim', $rows[$headerRow]);
mtrace('[UKD] Headers detected: ' . json_encode($headers, JSON_UNESCAPED_UNICODE));

$courseCol = self::find_column_index($headers, self::COLUMN_COURSE_NAME);
$assignCol = self::find_column_index($headers, self::COLUMN_ASSIGNMENT_NAME);
$gradeCol = self::find_column_index($headers, self::COLUMN_MAX_GRADE);

mtrace("[UKD] Column indices: Course='{$courseCol}', Assignment='{$assignCol}', Grade='{$gradeCol}'");

if ($courseCol === null || $assignCol === null || $gradeCol === null) {
    mtrace('[UKD] One or more required columns not found');
    return null;
}

// Search for matching row
$found = false;
for ($i = $headerRow + 1; $i <= count($rows); $i++) {
    if (!isset($rows[$i][$courseCol]) || !isset($rows[$i][$assignCol])) {
        continue;
    }

    $rowCourse = trim((string)($rows[$i][$courseCol] ?? ''));
    $rowAssign = trim((string)($rows[$i][$assignCol] ?? ''));
    
    // Нормализация: убираем множественные пробелы и спецсимволы
    $rowCourse = preg_replace('/\s+/', ' ', $rowCourse);
    $rowAssign = preg_replace('/\s+/', ' ', $rowAssign);
    $searchCourse = preg_replace('/\s+/', ' ', substr($coursefullname, 0, strrpos($coursefullname, ' ')));
    $searchAssign = preg_replace('/\s+/', ' ', $assignmentname);

        mtrace("[UKD] Comparing row {$i}: Course='{$rowCourse}' vs '{$searchCourse}', Assign='{$rowAssign}' vs '{$searchAssign}'");
        mtrace("[UKD] strcasecmp results: Course=" . (strcasecmp($rowCourse, $searchCourse) === 0 ? 'MATCH' : 'NO MATCH') . ", Assign=" . (strcasecmp($rowAssign, $searchAssign) === 0 ? 'MATCH' : 'NO MATCH'));


    if (strcasecmp($rowCourse, $searchCourse) === 0 && 
        strcasecmp($rowAssign, $searchAssign) === 0) {
        
        $grade = trim((string)($rows[$i][$gradeCol] ?? ''));
        $gradeInt = (int)$grade;
        mtrace("[UKD] MATCH FOUND at row {$i}: Grade={$gradeInt}");
        return ($gradeInt > 0) ? $gradeInt : null;
    }
}

mtrace('[UKD] No matching row found after scanning ' . count($rows) . ' data rows');
return null;
    }

    /**
     * Get fallback grade from plugin settings
     *
     * @return int Fallback grade value
     */
    public static function get_fallback_grade(): int {
        if (get_config('tool_modeussync', 'ukd_enable_fallback')) {
            $fallback = get_config('tool_modeussync', 'ukd_fallback_grade');
            return $fallback ? (int)$fallback : 100;
        }
        return 100; // Default fallback
    }

    /**
     * Get stored file object for UKD table
     *
     * @return stored_file|null
     */
    private static function get_ukd_file(): ?stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'tool_modeussync',
            'ukd_table',
            0,
            'itemid, filepath, filename',
            false
        );

        return empty($files) ? null : reset($files);
   }


/**
 * Load spreadsheet using PhpSpreadsheet - Moodle 4.4 compatible
 * 
 * @param stored_file $file
 * @return \PhpOffice\PhpSpreadsheet\Spreadsheet|null
 */
private static function load_spreadsheet(stored_file $file): ?\PhpOffice\PhpSpreadsheet\Spreadsheet {
    global $CFG;
    
    // 1. Проверяем наличие библиотеки
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        $composerAutoload = $CFG->dirroot . '/lib/phpspreadsheet/vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once($composerAutoload);
        }
        
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            error_log('[UKD] PhpSpreadsheet\IOFactory class NOT found');
            return null;
        }
    }
    
    $tempfile = null;
    
    try {
        // 2. Логируем информацию о файле
        error_log('[UKD] File: ' . $file->get_filename() . ', size: ' . $file->get_filesize() . ' bytes');
        
        // 3. Создаём временный файл и записываем в него содержимое
        $tempfile = tempnam(sys_get_temp_dir(), 'ukd_') . '.xlsx';
        $content = $file->get_content(); // ✅ Правильный метод для Moodle 4.4
        
        if ($content === false || $content === null) {
            error_log('[UKD] Failed to get file content');
            if ($tempfile && file_exists($tempfile)) {
                unlink($tempfile);
            }
            return null;
        }
        
        $bytesWritten = file_put_contents($tempfile, $content);
        unset($content); // Освобождаем память
        
        if ($bytesWritten === false || $bytesWritten === 0) {
            error_log('[UKD] Failed to write temp file');
            if ($tempfile && file_exists($tempfile)) {
                unlink($tempfile);
            }
            return null;
        }
        
        error_log('[UKD] Temp file: ' . $tempfile . ', size: ' . filesize($tempfile) . ' bytes');
        
        // 4. Проверяем ZIP-заголовок (xlsx — это ZIP-архив)
        $handle = fopen($tempfile, 'rb');
        if ($handle === false) {
            error_log('[UKD] Cannot open temp file for reading');
            unlink($tempfile);
            return null;
        }
        $header = fread($handle, 4);
        fclose($handle);
        
        if (strlen($header) < 4 || $header[0] !== "\x50" || $header[1] !== "\x4B") {
            error_log('[UKD] Invalid ZIP header: ' . bin2hex($header));
            unlink($tempfile);
            return null;
        }
        
        // 5. Загружаем через PhpSpreadsheet
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tempfile);
        
        // 6. Очищаем временный файл
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        
        error_log('[UKD] Spreadsheet loaded: ' . $spreadsheet->getSheetCount() . ' worksheet(s)');
        return $spreadsheet;
        
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        error_log('[UKD] Reader Exception: ' . $e->getMessage());
        if ($tempfile && file_exists($tempfile)) {
            unlink($tempfile);
        }
        return null;
    } catch (\Exception $e) {
        error_log('[UKD] Exception: ' . get_class($e) . ' - ' . $e->getMessage());
        if ($tempfile && file_exists($tempfile)) {
            unlink($tempfile);
        }
        return null;
    }
}

    /* Find the header row containing expected column names
     *
     * @param array $rows
     * @return int|null Row index (1-based) or null if not found
     */
    private static function find_header_row(array $rows): ?int {
        $expectedCols = [self::COLUMN_COURSE_NAME, self::COLUMN_ASSIGNMENT_NAME, self::COLUMN_MAX_GRADE];

        // Check first 10 rows for headers
        for ($i = 1; $i <= min(10, count($rows)); $i++) {
            if (!isset($rows[$i])) continue;

            $rowValues = array_map('trim', $rows[$i]);
            $found = 0;
            foreach ($expectedCols as $col) {
                foreach ($rowValues as $cell) {
                    if (strcasecmp($cell, $col) === 0) {
                        $found++;
                        break;
                    }
                }
            }
            if ($found === count($expectedCols)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Find column index by header name (case-insensitive)
     *
     * @param array $headers
     * @param string $columnName
     * @return string|null Column letter (e.g., 'A', 'B') or null
     */
    private static function find_column_index(array $headers, string $columnName): ?string {
        foreach ($headers as $colLetter => $value) {
            if (strcasecmp(trim($value), $columnName) === 0) {
                return $colLetter;
            }
        }
        return null;
    }
}
