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

namespace tool_s3logs;

use tool_s3logs\task\process_logs;

/**
 * Unit tests for process_logs task.
 *
 * @package    tool_s3logs
 * @category   test
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_s3logs\task\process_logs
 */
final class process_logs_test extends \advanced_testcase {
    /** @var int 18 months in seconds (plugin default max log age). */
    private const DEFAULT_INTERVAL = 60 * 60 * 24 * 30 * 18;

    /**
     * Invoke a private method on a fresh process_logs instance via reflection.
     *
     * @param string $method Method name.
     * @param array  $args   Arguments to pass.
     * @return mixed
     */
    private function invoke_private(string $method, array $args = []) {
        $task = new process_logs();
        $ref  = new \ReflectionMethod(process_logs::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($task, $args);
    }

    /**
     * Data provider for get_course_filter_sql tests.
     *
     * @return array
     */
    public static function get_course_filter_sql_provider(): array {
        return [
            'empty courseids' => ['', 'include', '', []],
            'whitespace-only courseids' => ['   ', 'include', '', []],
            'only one comma provided' => [',', 'include', '', []],
            'include mode with multiple IDs' => ['0,42', 'include', ' AND courseid IN (?,?)', [0, 42]],
            'exclude mode with multiple IDs' => ['0,1', 'exclude', ' AND courseid NOT IN (?,?)', [0, 1]],
            'non-numeric tokens' => ['abc,foo,bar', 'include', '', []],
            'double commas produce no empty tokens' => ['42,,107', 'include', ' AND courseid IN (?,?)', [42, 107]],
            'trailing comma produces no phantom zero' => ['42,', 'include', ' AND courseid = ?', [42]],
        ];
    }

    /**
     * Various tests for the courseids and the coursefiltermode settings.
     *
     * @param string $courseids          The courseids setting value to test.
     * @param string $coursefiltermode   The coursefiltermode setting value to test.
     * @param string $expectedsql        The expected SQL snippet returned by get_course_filter
     * @param array  $expectedparams     The expected params array returned by get_course_filter_sql.
     *
     * @dataProvider get_course_filter_sql_provider
     * @covers \tool_s3logs\task\process_logs::get_course_filter_sql
     */
    public function test_get_course_filter_sql($courseids, $coursefiltermode, $expectedsql, $expectedparams): void {
        $config = (object)['courseids' => $courseids, 'coursefiltermode' => $coursefiltermode];
        [$sql, $params] = $this->invoke_private('get_course_filter_sql', [$config]);

        $this->assertSame($expectedsql, $sql);
        $this->assertSame($expectedparams, $params);
    }

    /**
     * With no course filter, all sufficiently old records are extracted.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     */
    public function test_extract_records_returns_all_old_logs(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
        ];

        $record->courseid = 0;
        $id1 = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 42;
        $id2 = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 100;
        $id3 = $DB->insert_record('logstore_standard_log', $record);
        // Recent record — must not be extracted.
        $record->timecreated = time();
        $record->courseid = 0;
        $DB->insert_record('logstore_standard_log', $record);

        $this->assertEquals(4, $DB->count_records('logstore_standard_log'));

        $config   = (object)['courseids' => '', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertContains($id3, $ids);
        $this->assertCount(3, $ids);
    }

    /**
     * Records newer than the max-age threshold are never extracted.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     */
    public function test_extract_records_excludes_recent_logs(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time(),
        ];
        $DB->insert_record('logstore_standard_log', $record);
        $record->timecreated = time() - 60;
        $DB->insert_record('logstore_standard_log', $record);

        $config   = (object)['courseids' => '', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertEmpty($ids);
    }

    /**
     * Include filter: only records matching the listed course IDs are extracted.
     * Primary use case: courseid = 0 targets logs outside any course context.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     * @covers \tool_s3logs\task\process_logs::get_course_filter_sql
     */
    public function test_extract_records_include_filter_targets_courseid_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
        ];

        $record->courseid = 0;
        $site = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 42;
        $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 100;
        $DB->insert_record('logstore_standard_log', $record);

        $config   = (object)['courseids' => '0', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertContains($site, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * Include filter with multiple course IDs extracts all matching records.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     * @covers \tool_s3logs\task\process_logs::get_course_filter_sql
     */
    public function test_extract_records_include_filter_multiple_courses(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
        ];

        $record->courseid = 0;
        $id0  = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 42;
        $id42 = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 100;
        $DB->insert_record('logstore_standard_log', $record);

        $config   = (object)['courseids' => '0,42', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertContains($id0, $ids);
        $this->assertContains($id42, $ids);
        $this->assertCount(2, $ids);
    }

    /**
     * Exclude filter: records matching the listed course IDs are skipped.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     * @covers \tool_s3logs\task\process_logs::get_course_filter_sql
     */
    public function test_extract_records_exclude_course_filter(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
        ];

        $record->courseid = 0;
        $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 42;
        $id42  = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 100;
        $id100 = $DB->insert_record('logstore_standard_log', $record);

        $config   = (object)['courseids' => '0', 'coursefiltermode' => 'exclude'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertContains($id42, $ids);
        $this->assertContains($id100, $ids);
        $this->assertCount(2, $ids);
    }

    /**
     * All-non-numeric courseids drops the filter — all old records are returned.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     * @covers \tool_s3logs\task\process_logs::get_course_filter_sql
     */
    public function test_extract_records_non_numeric_courseids_returns_all(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
        ];

        $record->courseid = 0;
        $id1 = $DB->insert_record('logstore_standard_log', $record);
        $record->courseid = 42;
        $id2 = $DB->insert_record('logstore_standard_log', $record);

        $config   = (object)['courseids' => 'abc,xyz', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertCount(2, $ids);
    }

    // Prefix / keyname tests.

    /**
     * The S3 keyname is {prefix}_{YmdHis}_{first}_{last}.csv.
     * Verify different prefixes produce correctly formatted keynames.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     */
    public function test_keyname_uses_configured_prefix(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $record = (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
            'courseid'          => 0,
        ];
        $DB->insert_record('logstore_standard_log', $record);
        $DB->insert_record('logstore_standard_log', $record);

        foreach (['myprefix', 'logs', 'archive'] as $prefix) {
            $config   = (object)['courseids' => '', 'coursefiltermode' => 'include'];
            $tempdir  = make_temp_directory('s3logs_test');
            $tempfile = tempnam($tempdir, 's3logs_test_');
            $fp       = fopen($tempfile, 'w');

            $this->expectOutputRegex('/Getting records older than/');
            $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
            fclose($fp);

            $this->assertNotEmpty($ids);

            $keyname = $prefix . '_' . date('YmdHis') . '_' . min($ids) . '_' . max($ids) . '.csv';
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($prefix, '/') . '_\d{14}_\d+_\d+\.csv$/',
                $keyname,
                "Keyname '$keyname' does not match expected format for prefix '$prefix'"
            );
        }
    }

    /**
     * An empty prefix produces a keyname starting with an underscore.
     *
     * @covers \tool_s3logs\task\process_logs::extract_records
     */
    public function test_keyname_with_empty_prefix(): void {
        global $DB;
        $this->resetAfterTest();

        $ctx = \context_system::instance();
        $DB->insert_record('logstore_standard_log', (object)[
            'edulevel'          => 0,
            'contextid'         => $ctx->id,
            'contextlevel'      => $ctx->contextlevel,
            'contextinstanceid' => $ctx->instanceid,
            'userid'            => 1,
            'timecreated'       => time() - self::DEFAULT_INTERVAL - 1,
            'courseid'          => 0,
        ]);

        $config   = (object)['courseids' => '', 'coursefiltermode' => 'include'];
        $tempdir  = make_temp_directory('s3logs_test');
        $tempfile = tempnam($tempdir, 's3logs_test_');
        $fp       = fopen($tempfile, 'w');

        $this->expectOutputRegex('/Getting records older than/');
        $ids = $this->invoke_private('extract_records', [time() + 3600, self::DEFAULT_INTERVAL, $fp, $config]);
        fclose($fp);

        $this->assertNotEmpty($ids);

        $keyname = '' . '_' . date('YmdHis') . '_' . min($ids) . '_' . max($ids) . '.csv';
        $this->assertStringStartsWith('_', $keyname);
        $this->assertStringEndsWith('.csv', $keyname);
    }
}
