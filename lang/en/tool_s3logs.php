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
 * Plugin strings are defined here.
 *
 * @package     tool_s3logs
 * @category    string
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'S3 Log Archiver';
$string['pluginnamedesc'] = 'Moodle to Amazon S3 Log Archiver';

$string['archivesettings'] = 'Log Archive Settings';
$string['archivesettings_desc'] = 'settings for log archiving';
$string['awss3settings'] = 'Amazon S3 Settings';
$string['awss3settings_desc'] = 'Settings for AWS and S3 access';
$string['bucket'] = 'Bucket';
$string['bucket_desc'] = 'The name of the bucket to store the logs in.';
$string['enable'] = 'Enable log archiver';
$string['enable_desc'] = 'Enable log archive tasks Help with Enable log archive tasks';
$string['generalsettings'] = 'General Settings';
$string['generalsettings_desc'] = 'Settings for the general behaviour of the plugin.';
$string['keyid'] = 'Key ID';
$string['keyid_desc'] = 'The AWS API key used to make AWS API calls for S3';
$string['processlogs'] = 'Run the S3 log processing task';
$string['maxlogage'] = 'Maximum age of log entries (months)';
$string['maxlogage_desc'] = 'Specifies the maximum age of log entriesi (in months) before the archiver starts archiving it to Amazon S3';
$string['maxruntime'] = 'Maximum log archive task runtime';
$string['maxruntime_desc'] = 'Background tasks handle the archiving and truncating of the Moodle log table. This setting controlls the maximum runtime for all S3 logs related tasks.';
$string['prefix'] = 'Log file prefix';
$string['prefix_desc'] = 'The prefix applied to the uploaded log filename.';
$string['s3region'] = 'AWS Region';
$string['s3region_desc'] = 'The AWS Region to use for API calls';
$string['secretkey'] = 'Secret Key';
$string['secretkey_desc'] = 'The AWS secret key used to make AWS API calls for S3';
$string['privacy:metadata'] = 's3logs tool export Moodle standard log for archiving purposes';


