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
 * AI credits are held and charged by LMS Labs (1 credit per answer marked by AI). This plugin keeps a log
 * of what each activity used, from what LMS Labs reports it charged.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credits {
    /**
     * The site's LMS Labs credit balance, -1 when it is not known (not connected), or -2 when the site has unlimited credits.
     *
     * @return int
     */
    public static function balance(): int {
        if (\mod_aiscience\ai\generator::provider() === 'mock') {
            return max(0, (int)get_config('mod_aiscience', 'mockbalance'));
        }
        return (int)\mod_aiscience\ai\lmslabs::status()['balance'];
    }

    /**
     * Credits used by one activity.
     *
     * @param int $aiscienceid
     * @return int
     */
    public static function used(int $aiscienceid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(credits), 0) FROM {aiscience_credit} WHERE aiscienceid = ?',
            [$aiscienceid]
        );
    }
}
