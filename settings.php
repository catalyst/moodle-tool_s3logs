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
 * Plugin administration pages are defined here.
 *
 * @package     tool_s3logs
 * @category    admin
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $PAGE;

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_s3logs', get_string('pluginname', 'tool_s3logs'));
    $ADMIN->add('tools', $settings);

    $settings->add(new admin_setting_heading('tool_s3logs_settings', '', get_string('pluginnamedesc', 'tool_s3logs')));

    if (! during_initial_install ()) {
        // General Settings.
        $settings->add(new admin_setting_heading('tool_s3logs_general',
                get_string('generalsettings', 'tool_s3logs'),
                get_string('generalsettings_desc', 'tool_s3logs')
                ));
        $settings->add(new admin_setting_configcheckbox('tool_s3logs/enable',
                get_string('enable', 'tool_s3logs'),
                get_string('enable_desc', 'tool_s3logs'), 0));

        $settings->add(new admin_setting_configduration('tool_s3logs/maxruntime',
                get_string('maxruntime', 'tool_s3logs' ),
                get_string('maxruntime_desc', 'tool_s3logs'),
                '86400'));

        // Log Archive settings.
        $settings->add(new admin_setting_heading('tool_s3logs_archive',
                get_string('archivesettings', 'tool_s3logs'),
                get_string('archivesettings_desc', 'tool_s3logs')
                ));
        $settings->add(new admin_setting_configcheckbox('tool_s3logs/usesdkcreds',
                get_string('usesdkcreds', 'tool_s3logs'),
                get_string('usesdkcreds_desc', 'tool_s3logs'), 0));

        $settings->add(new admin_setting_configtext('tool_s3logs/maxlogage',
                get_string('maxlogage', 'tool_s3logs' ),
                get_string('maxlogage_desc', 'tool_s3logs'),
                18, PARAM_INT));

        $settings->add(new admin_setting_configtext('tool_s3logs/prefix',
                get_string('prefix', 'tool_s3logs' ),
                get_string('prefix_desc', 'tool_s3logs'),
                '', PARAM_ALPHA));

        // AWS Bucket and S3 setttings.
        $settings->add(new admin_setting_heading('tool_s3logs_awss3',
                get_string('awss3settings', 'tool_s3logs'),
                get_string('awss3settings_desc', 'tool_s3logs')
                ));

        $settings->add(new admin_setting_configtext('tool_s3logs/bucket',
                get_string('bucket', 'tool_s3logs' ),
                get_string('bucket_desc', 'tool_s3logs'),
                '', PARAM_TEXT));

        $settings->add(new admin_setting_configtext('tool_s3logs/keyid',
                get_string('keyid', 'tool_s3logs' ),
                get_string('keyid_desc', 'tool_s3logs'),
                '', PARAM_TEXT));

        $settings->add(new admin_setting_configpasswordunmask('tool_s3logs/secretkey',
                get_string('secretkey', 'tool_s3logs' ),
                get_string('secretkey_desc', 'tool_s3logs'),
                ''));

        $regionoptions = array(
               'us-east-1'      => 'us-east-1',
               'us-east-2'      => 'us-east-2',
               'us-west-1'      => 'us-west-1',
               'us-west-2'      => 'us-west-2',
               'ap-northeast-2' => 'ap-northeast-2',
               'ap-southeast-1' => 'ap-southeast-1',
               'ap-southeast-2' => 'ap-southeast-2',
               'ap-northeast-1' => 'ap-northeast-1',
               'eu-central-1'   => 'eu-central-1',
               'eu-west-1'      => 'eu-west-1'
                );
        $settings->add(new admin_setting_configselect('tool_s3logs/s3region',
                get_string('s3region', 'tool_s3logs' ),
                get_string('s3region_desc', 'tool_s3logs'),
               'ap-southeast-2', $regionoptions));
    }
}