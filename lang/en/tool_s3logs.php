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

$string['archivesettings'] = 'Log Archive Settings';
$string['awss3settings'] = 'Amazon S3 Settings';
$string['awss3settings_desc'] = 'Settings for AWS and S3 access';
$string['bucket'] = 'Bucket';
$string['bucket_desc'] = 'The name of the bucket to store the logs in.';
$string['checkstatus'] = 'S3 Log Archiver status';
$string['connectionfailure'] = 'Could not establish connection to S3 storage. {$a}';
$string['connectionsuccess'] = 'Could establish connection to the S3 storage.';
$string['coursefiltermode'] = 'Course filter mode';
$string['coursefiltermode_desc'] = 'Whether the course IDs listed above should be included in or excluded from archiving.';
$string['coursefiltermode_exclude'] = 'Exclude — archive all logs except those matching the listed course IDs';
$string['coursefiltermode_include'] = 'Include — only archive logs matching the listed course IDs';
$string['courseids'] = 'Course ID filter';
$string['courseids_desc'] = 'Comma-separated list of course IDs to include or exclude from archiving (e.g. <code>0,42,107</code>). Leave blank to archive logs for all courses. Use <code>0</code> to target logs that do not belong to any course, and <code>1</code> for logs belonging to the site home page - so <code>0,1</code> captures all logs outside of a real course context.';
$string['enable'] = 'Enable log archiver';
$string['enable_desc'] = 'Enable log archive tasks';
$string['generalsettings'] = 'General Settings';
$string['keyid'] = 'Key ID';
$string['keyid_desc'] = 'The AWS API key used to make AWS API calls for S3';
$string['logarchiverdisabled'] = 'Log archive tasks are disabled';
$string['maxlogage'] = 'Maximum age of log entries';
$string['maxlogage_desc'] = 'Specifies the maximum age of log entries before the archiver starts archiving them to Amazon S3.';
$string['maxruntime'] = 'Maximum log archive task runtime';
$string['maxruntime_desc'] = 'Background tasks handle the archiving and truncating of the Moodle log table. This setting controls the maximum runtime for all S3 logs related tasks.';
$string['maxruntimeexceeded'] = 'Connection was OK, but the last log archive run hit its maximum runtime (> {$a}).';
$string['maxruntimeexceeded_details'] = 'The last run took {$a->duration}s, at or beyond the configured maximum runtime of {$a->maxruntime}s. This usually means there were still eligible records left to archive when time ran out. If this happens repeatedly, the task is not keeping pace with new logs being created, and the maximum runtime setting should be increased.';
$string['notconfigured'] = 'Missing configuration.';
$string['permissioncheckpassed'] = 'Permissions check passed.';
$string['pluginname'] = 'S3 Log Archiver';
$string['pluginnamedesc'] = 'Moodle to Amazon S3 Log Archiver';

$string['prefix'] = 'Log file prefix';
$string['prefix_desc'] = 'The prefix applied to the uploaded log filename.';
$string['privacy:metadata'] = 's3logs tool export Moodle standard log for archiving purposes';
$string['privacy:metadata:tool_s3logs:externalpurpose'] = 's3logs tool exports Moodle standard log records for archiving purposes';
$string['privacy:metadata:tool_s3logs:realuserid'] = 'The ID of the real user behind the event, when masquerading a user.';
$string['privacy:metadata:tool_s3logs:relateduserid'] = 'The ID of a user related to an event';
$string['privacy:metadata:tool_s3logs:userid'] = 'The ID of the user who triggered an event';
$string['processlogs'] = 'Run the S3 log processing task';
$string['progress_archiving'] = 'Archiving records created between {$a->start} and {$a->end}';
$string['progress_complete'] = 'Archiving complete: {$a} records processed';
$string['progress_deleting'] = 'Deleting {$a} records from the database';
$string['progress_extracted'] = 'Extracting: {$a}';
$string['progress_extraction_complete'] = 'Extracted {$a->count} records to {$a->tempfile} (took {$a->elapsed}s)';
$string['progress_finding'] = 'Finding oldest eligible record';
$string['progress_memory'] = 'Mem: {$a}';
$string['progress_norecords'] = 'No records extracted before time limit was reached.';
$string['progress_slice'] = 'Processing slice: {$a->start} to {$a->end}';
$string['progress_slice_empty'] = 'No records found in this slice, moving to next slice';
$string['progress_uploading'] = 'Uploading {$a->count} records to {$a->keyname}';
$string['progress_vacuuming'] = 'Running VACUUM on logstore_standard_log';
$string['s3region'] = 'AWS Region';
$string['s3region_desc'] = 'The AWS Region to use for API calls';
$string['sdkcredserror'] = 'Couldn\'t find AWS credentials. It\'s unsafe to enable this setting. Follow up <a href="https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html">AWS documentation</a>.';
$string['sdkcredsok'] = 'AWS credentials found. This setting can be safely enabled.';
$string['secretkey'] = 'Secret Key';
$string['secretkey_desc'] = 'The AWS secret key used to make AWS API calls for S3';
$string['usesdkcreds'] = 'Use the default credential provider chain to find AWS credentials';
$string['usesdkcreds_desc'] = 'If Moodle is hosted inside AWS, the default credential chain can be used for access to s3 logs.';
$string['vacuumafterdelete'] = 'Vacuum logstore table after archiving';
$string['vacuumafterdelete_desc'] = 'Issue a <code>VACUUM</code> on the <code>logstore_standard_log</code> table after each archiving run that deletes records. This reclaims dead tuple space immediately rather than waiting for autovacuum, which can fall behind during aggressive archiving campaigns. This setting only has effect on PostgreSQL and is silently ignored on other database families.';
$string['viewbucketinconsole'] = 'View bucket contents in the AWS console';
$string['writefailure'] = 'Could not write object to the S3 storage. {$a}';
