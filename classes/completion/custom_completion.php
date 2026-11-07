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

namespace mod_aiscience\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules: submits a report, records data.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * State of a rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $instanceid = $this->cm->instance;
        if ($rule === 'completionsubmit') {
            $done = $DB->record_exists_select(
                'aiscience_attempt',
                "aiscienceid = ? AND userid = ? AND status IN ('submitted', 'graded')",
                [$instanceid, $this->userid]
            );
        } else {
            $done = $DB->record_exists_select(
                'aiscience_attempt',
                'aiscienceid = ? AND userid = ? AND readings > 0',
                [$instanceid, $this->userid]
            );
        }
        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Rules this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionsubmit', 'completiondata'];
    }

    /**
     * Descriptions.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_aiscience'),
            'completiondata' => get_string('completiondetail:data', 'mod_aiscience'),
        ];
    }

    /**
     * Sort order.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completiondata', 'completionsubmit', 'completionusegrade'];
    }
}
