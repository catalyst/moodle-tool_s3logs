![GitHub Workflow Status (branch)](https://img.shields.io/github/workflow/status/catalyst/moodle-tool_s3logs/ci/MOODLE_405_STABLE)


# Moodle to Amazon S3 Log Archiver #

This plugin will take entries from teh Moodle standard log store table and export them to AWS S3.

There is no internal cleanup process in Moodle to manage the size of the standard log store table. This table will get continuosly larger overtime. On busy Moodle sites this means that the table can easily be over 100GB on disk. As the standard log store table grows, both in number of records and size on disk Moodle performance can be effected.

This plugin will extract entries from the standard log store table that are older than a user configured date. These extracted entries are then uploaded to AWS S3 as a csv file, and finally the original records are deleted from the Moodle database. Doing this keeps the Moodle databse size down while preserving data that can be leverage for analytics and other functions.

The plugin functionality runs as a Moodle scheduled task.

## Supported Moodle Versions
This plugin currently supports Moodle:

| Moodle version    | Branch            |
|-------------------|-------------------|
| Moodle 4.5+       | MOODLE_405_STABLE |
| Moodle 3.5 to 4.1 | master            |



## Installation

1. Get the code and copy/ install it to: `<moodledir>/admin/tool/s3logs`
2. Run the upgrade: `sudo -u www-data php admin/cli/upgrade` **Note:** the user may be different to www-data on your system.

## Configuration
1. Configure the plugin in *Site administration > Plugins > Admin Tools > S3 log archiver*.
2. The schedule for the plugin task can be altered at *Site administration > Server > Scheduled tasks*
3. The scheduled task can also be run manually from your *moodledir*. `sudo -u www-data php admin/tool/task/cli/schedule_task.php --execute=\\tool_s3logs\\task\\process_logs`  **Note:** the user may be different to www-data on your system.


# Crafted by Catalyst IT


This plugin was developed by Catalyst IT Australia:

https://www.catalyst-au.net/

![Catalyst IT](/pix/catalyst-logo.png?raw=true)


# Contributing and Support

Issues, and pull requests using github are welcome and encouraged! 

https://github.com/catalyst/moodle-tool_s3logs/issues

If you would like commercial support or would like to sponsor additional improvements
to this plugin please contact us:

https://www.catalyst-au.net/contact-us


## License ##

2017 Matt Porritt <mattp@catalyst-au.net>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program.  If not, see <http://www.gnu.org/licenses/>.
