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

/**
 * Backup task.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aiscience/backup/moodle2/backup_aiscience_stepslib.php');

/**
 * Backup activity task.
 */
class backup_aiscience_activity_task extends backup_activity_task {
    /**
     * No settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Steps.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_aiscience_activity_structure_step('aiscience_structure', 'aiscience.xml'));
    }

    /**
     * Encode links.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '/');
        $content = preg_replace('/(' . $base . '\/mod\/aiscience\/index.php\?id\=)([0-9]+)/', '$@AISCIENCEINDEX*$2@$', $content);
        $content = preg_replace('/(' . $base . '\/mod\/aiscience\/view.php\?id\=)([0-9]+)/', '$@AISCIENCEVIEWBYID*$2@$', $content);
        return $content;
    }
}
