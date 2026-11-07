<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_aiscience\local;

/**
 * Pulls plain text out of uploaded curriculum files so the AI can align to the teacher's own wording.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curriculum {
    /** @var int Most curriculum text kept, in characters. */
    const MAXCHARS = 20000;

    /**
     * Extract text from all curriculum files in a context.
     *
     * @param int $contextid
     * @return string
     */
    public static function extract_text(int $contextid): string {
        $fs = get_file_storage();
        $parts = [];
        foreach ($fs->get_area_files($contextid, 'mod_aiscience', 'curriculum', 0, 'filename', false) as $file) {
            $parts[] = self::file_text($file);
        }
        $text = trim(preg_replace("/[ \t]+/", ' ', implode("\n\n", array_filter($parts))));
        return \core_text::substr($text, 0, self::MAXCHARS);
    }

    /**
     * Text of one stored file.
     *
     * @param \stored_file $file
     * @return string
     */
    public static function file_text(\stored_file $file): string {
        $name = \core_text::strtolower($file->get_filename());
        if (preg_match('/\.(txt|csv)$/', $name)) {
            return (string)$file->get_content();
        }
        if (preg_match('/\.docx$/', $name)) {
            $tmp = $file->copy_content_to_temp();
            $zip = new \ZipArchive();
            $text = '';
            if ($tmp && $zip->open($tmp) === true) {
                $xml = (string)$zip->getFromName('word/document.xml');
                $zip->close();
                $xml = preg_replace('~</w:p>~', "\n", $xml);
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            @unlink($tmp);
            return $text;
        }
        if (preg_match('/\.pdf$/', $name)) {
            $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
            if ($bin === '') {
                return '';
            }
            $tmp = $file->copy_content_to_temp();
            $out = (string)@shell_exec(escapeshellcmd($bin) . ' -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
            @unlink($tmp);
            return $out;
        }
        return '';
    }
}
