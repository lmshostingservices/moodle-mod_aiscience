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
 * Restore structure.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_aiscience_activity_structure_step extends restore_activity_structure_step {
    /** @var int old published version id */
    protected $oldcurrent = 0;

    /**
     * Paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [
            new restore_path_element('aiscience', '/activity/aiscience'),
            new restore_path_element('aiscience_version', '/activity/aiscience/versions/version'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('aiscience_attempt', '/activity/aiscience/attempts/attempt');
            $paths[] = new restore_path_element('aiscience_note', '/activity/aiscience/attempts/attempt/notes/note');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Instance.
     *
     * @param array $data
     */
    protected function process_aiscience($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $this->oldcurrent = (int)$data->currentversion;
        $data->currentversion = 0;
        $newid = $DB->insert_record('aiscience', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Version.
     *
     * @param array $data
     */
    protected function process_aiscience_version($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aiscienceid = $this->get_new_parentid('aiscience');
        $data->createdby = $this->get_mappingid('user', $data->createdby) ?: 0;
        $data->basedon = empty($data->basedon) ? 0 : ($this->get_mappingid('aiscience_version', $data->basedon) ?: 0);
        $newid = $DB->insert_record('aiscience_version', $data);
        $this->set_mapping('aiscience_version', $oldid, $newid);
        if ($oldid == $this->oldcurrent) {
            $DB->set_field('aiscience', 'currentversion', $newid, ['id' => $data->aiscienceid]);
        }
    }

    /**
     * Attempt.
     *
     * @param array $data
     */
    protected function process_aiscience_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aiscienceid = $this->get_new_parentid('aiscience');
        $data->versionid = $this->get_mappingid('aiscience_version', $data->versionid) ?: 0;
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->grader = $this->get_mappingid('user', $data->grader) ?: 0;
        $newid = $DB->insert_record('aiscience_attempt', $data);
        $this->set_mapping('aiscience_attempt', $oldid, $newid);
    }

    /**
     * Note.
     *
     * @param array $data
     */
    protected function process_aiscience_note($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->attemptid = $this->get_new_parentid('aiscience_attempt');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $newid = $DB->insert_record('aiscience_note', $data);
        $this->set_mapping('aiscience_note', $oldid, $newid, true);
    }

    /**
     * Files.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aiscience', 'intro', null);
        $this->add_related_files('mod_aiscience', 'curriculum', null);
        $this->add_related_files('mod_aiscience', 'note', 'aiscience_note');
    }
}
