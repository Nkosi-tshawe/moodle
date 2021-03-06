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
 * Blocks external API
 *
 * @package    core_block
 * @category   external
 * @copyright  2017 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.3
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

/**
 * Blocks external functions
 *
 * @package    core_block
 * @category   external
 * @copyright  2015 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.3
 */
class core_block_external extends external_api {


    /**
     * Returns a block structure.
     *
     * @return external_single_structure a block single structure.
     * @since  Moodle 3.6
     */
    private static function get_block_structure() {
        return new external_single_structure(
            array(
                'instanceid'    => new external_value(PARAM_INT, 'Block instance id.'),
                'name'          => new external_value(PARAM_PLUGIN, 'Block name.'),
                'region'        => new external_value(PARAM_ALPHANUMEXT, 'Block region.'),
                'positionid'    => new external_value(PARAM_INT, 'Position id.'),
                'collapsible'   => new external_value(PARAM_BOOL, 'Whether the block is collapsible.'),
                'dockable'      => new external_value(PARAM_BOOL, 'Whether the block is dockable.'),
                'weight'        => new external_value(PARAM_INT, 'Used to order blocks within a region.', VALUE_OPTIONAL),
                'visible'       => new external_value(PARAM_BOOL, 'Whether the block is visible.', VALUE_OPTIONAL),
            ), 'Block information.'
        );
    }

    /**
     * Convenience function for getting all the blocks of the current $PAGE.
     *
     * @param bool $includeinvisible Whether to include not visible blocks or not
     * @return array Block information
     * @since  Moodle 3.6
     */
    private static function get_all_current_page_blocks($includeinvisible = false) {
        global $PAGE, $OUTPUT;

        // Load the block instances for all the regions.
        $PAGE->blocks->load_blocks($includeinvisible);
        $PAGE->blocks->create_all_block_instances();

        $allblocks = array();
        $blocks = $PAGE->blocks->get_content_for_all_regions($OUTPUT);
        foreach ($blocks as $region => $regionblocks) {
            $regioninstances = $PAGE->blocks->get_blocks_for_region($region);
            // Index block instances to retrieve required info.
            $blockinstances = array();
            foreach ($regioninstances as $ri) {
                $blockinstances[$ri->instance->id] = $ri->instance;
            }

            foreach ($regionblocks as $bc) {
                $allblocks[] = [
                    'instanceid' => $bc->blockinstanceid,
                    'name' => $blockinstances[$bc->blockinstanceid]->blockname,
                    'region' => $region,
                    'positionid' => $bc->blockpositionid,
                    'collapsible' => (bool) $bc->collapsible,
                    'dockable' => (bool) $bc->dockable,
                    'weight' => $blockinstances[$bc->blockinstanceid]->weight,
                    'visible' => $blockinstances[$bc->blockinstanceid]->visible,
                ];
            }
        }
        return $allblocks;
    }

    /**
     * Returns description of get_course_blocks parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.3
     */
    public static function get_course_blocks_parameters() {
        return new external_function_parameters(
            array(
                'courseid'  => new external_value(PARAM_INT, 'course id')
            )
        );
    }

    /**
     * Returns blocks information for a course.
     *
     * @param int $courseid The course id
     * @return array Blocks list and possible warnings
     * @throws moodle_exception
     * @since Moodle 3.3
     */
    public static function get_course_blocks($courseid) {
        global $PAGE;

        $warnings = array();
        $params = self::validate_parameters(self::get_course_blocks_parameters(), ['courseid' => $courseid]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        self::validate_context($context);

        // Specific layout for frontpage course.
        if ($course->id == SITEID) {
            $PAGE->set_pagelayout('frontpage');
            $PAGE->set_pagetype('site-index');
        } else {
            $PAGE->set_pagelayout('course');
            // Ensure course format is set (view course/view.php).
            $course->format = course_get_format($course)->get_format();
            $PAGE->set_pagetype('course-view-' . $course->format);
        }

        $allblocks = self::get_all_current_page_blocks();

        return array(
            'blocks' => $allblocks,
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of get_course_blocks result values.
     *
     * @return external_single_structure
     * @since Moodle 3.3
     */
    public static function get_course_blocks_returns() {

        return new external_single_structure(
            array(
                'blocks' => new external_multiple_structure(self::get_block_structure(), 'List of blocks in the course.'),
                'warnings'  => new external_warnings(),
            )
        );
    }

    /**
     * Returns description of get_dashboard_blocks parameters.
     *
     * @return external_function_parameters
     * @since Moodle 3.6
     */
    public static function get_dashboard_blocks_parameters() {
        return new external_function_parameters(
            array(
                'userid'  => new external_value(PARAM_INT, 'User id (optional), default is current user.', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns blocks information for the given user dashboard.
     *
     * @param int $userid The user id to retrive the blocks from, optional, default is to current user.
     * @return array Blocks list and possible warnings
     * @throws moodle_exception
     * @since Moodle 3.6
     */
    public static function get_dashboard_blocks($userid = 0) {
        global $CFG, $USER, $PAGE;

        require_once($CFG->dirroot . '/my/lib.php');

        $warnings = array();
        $params = self::validate_parameters(self::get_dashboard_blocks_parameters(), ['userid' => $userid]);

        $userid = $params['userid'];
        if (empty($userid)) {
            $userid = $USER->id;
        }

        if ($USER->id != $userid) {
            // We must check if the current user can view other users dashboard.
            require_capability('moodle/site:config', context_system::instance());
            $user = core_user::get_user($userid, '*', MUST_EXIST);
            core_user::require_active_user($user);
        }

        $context = context_user::instance($userid);;
        self::validate_context($context);

        // Get the My Moodle page info.  Should always return something unless the database is broken.
        if (!$currentpage = my_get_page($userid, MY_PAGE_PRIVATE)) {
            throw new moodle_exception('mymoodlesetup');
        }

        $PAGE->set_context($context);
        $PAGE->set_pagelayout('mydashboard');
        $PAGE->set_pagetype('my-index');
        $PAGE->blocks->add_region('content');   // Need to add this special regition to retrieve the central blocks.
        $PAGE->set_subpage($currentpage->id);

        // Load the block instances in the current $PAGE for all the regions.
        $returninvisible = has_capability('moodle/my:manageblocks', $context) ? true : false;
        $allblocks = self::get_all_current_page_blocks($returninvisible);

        return array(
            'blocks' => $allblocks,
            'warnings' => $warnings
        );
    }

    /**
     * Returns description of get_dashboard_blocks result values.
     *
     * @return external_single_structure
     * @since Moodle 3.6
     */
    public static function get_dashboard_blocks_returns() {

        return new external_single_structure(
            array(
                'blocks' => new external_multiple_structure(self::get_block_structure(), 'List of blocks in the dashboard.'),
                'warnings'  => new external_warnings(),
            )
        );
    }
}
