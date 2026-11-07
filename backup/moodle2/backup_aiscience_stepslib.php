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
 * Backup structure.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_aiscience_activity_structure_step extends backup_activity_structure_step {
    /**
     * Structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $aiscience = new backup_nested_element('aiscience', ['id'], ['name', 'intro', 'introformat', 'question', 'yearlevel',
            'lessonlength', 'curriculum', 'ivariable', 'dvariable', 'datasources', 'equipment', 'safety', 'supports', 'extra',
            'librarykey', 'currentversion', 'grade', 'pooldata', 'aisuggest', 'completionsubmit', 'completiondata',
            'timecreated', 'timemodified']);
        $versions = new backup_nested_element('versions');
        $version = new backup_nested_element('version', ['id'], ['versionnum', 'status', 'source', 'plan', 'html', 'checks',
            'basedon', 'instruction', 'model', 'error', 'repairs', 'createdby', 'timecreated', 'timemodified']);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], ['versionid', 'userid', 'status', 'stage', 'stagename',
            'prediction', 'phases', 'readings', 'datasource', 'answers', 'report', 'grade', 'rubric', 'feedback', 'grader', 'marks',
            'timecreated', 'timemodified', 'timesubmitted', 'timegraded']);
        $notes = new backup_nested_element('notes');
        $note = new backup_nested_element('note', ['id'], ['userid', 'kind', 'content', 'stagelabel', 'exptime', 'timecreated']);

        $aiscience->add_child($versions);
        $versions->add_child($version);
        $aiscience->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($notes);
        $notes->add_child($note);

        $aiscience->set_source_table('aiscience', ['id' => backup::VAR_ACTIVITYID]);
        $version->set_source_table('aiscience_version', ['aiscienceid' => backup::VAR_PARENTID], 'id ASC');
        if ($userinfo) {
            $attempt->set_source_table('aiscience_attempt', ['aiscienceid' => backup::VAR_PARENTID], 'id ASC');
            $note->set_source_table('aiscience_note', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }
        $version->annotate_ids('user', 'createdby');
        $attempt->annotate_ids('user', 'userid');
        $attempt->annotate_ids('user', 'grader');
        $note->annotate_ids('user', 'userid');
        $aiscience->annotate_files('mod_aiscience', 'intro', null);
        $aiscience->annotate_files('mod_aiscience', 'curriculum', null);
        $note->annotate_files('mod_aiscience', 'note', 'id');
        return $this->prepare_activity_structure($aiscience);
    }
}
