<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for tool_s3logs.
 *
 * @package    tool_s3logs
 * @copyright  2018 Nathan Nguyen <nathannguyen@catalyst-net.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_s3logs\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\contextlist;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;

/**
 * The tool_s3logs privacy
 *
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Returns meta data about this system.
     *
     * @param   collection $collection The initialised collection to add items to.
     * @return  collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection) : collection {
        $collection->add_external_location_link('s3logs', [
            'userid' => 'privacy:metadata:tool_s3logs:userid',
            'relateduserid' => 'privacy:metadata:tool_s3logs:relateduserid',
            'realuserid' => 'privacy:tool_s3logs:log:realuserid',
        ], 'privacy:metadata:tool_s3logs:externalpurpose');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   int         $userid     The user to search.
     * @return  contextlist $contextlist  The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        return new contextlist();
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * Delete all use data which matches the specified deletion_criteria.
     *
     * @param \context $context A user context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

}
