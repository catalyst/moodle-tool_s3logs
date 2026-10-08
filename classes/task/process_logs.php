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

namespace tool_s3logs\task;

use tool_s3logs\local\client\s3_client;

/**
 * Class to process logs.
 *
 * @package     tool_s3logs
 * @category    task
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_logs extends \core\task\scheduled_task {
    use \core\task\stored_progress_task_trait;

    /** @var float The memory limit threshold as a fraction of the configured memory limit. */
    const MEMORY_LIMIT_THRESHOLD = 0.8; // Stop processing if we have used 80% of the memory limit.

    /**
     * @var int Minimum size (in seconds) of a backlog slice, regardless of how frequently the
     * task's cron schedule runs. Without this, a sub-daily schedule (e.g. hourly) would slice
     * a large backlog into one file per hour, which is needlessly granular; a day is a
     * reasonable lower bound for how finely to split up historical data.
     */
    const MIN_SLICE_SECONDS = DAYSECS;

    /**
     * @var ?string Early exit reason (memory/time/interrupt), set by extract_records(); mtraced
     * after the progress bar so it doesn't mess up the bar's output.
     */
    private ?string $earlyexitmessage = null;

    /**
     * {@inheritDoc}
     * @see \core\task\scheduled_task::get_name()
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('processlogs', 'tool_s3logs');
    }

    /**
     * Creates a temp file on the files system.
     * Returns the name inlcuding path of the file
     * and a file pointer.
     *
     * @return array File name and file pointer.
     */
    private function get_temp_file() {
        // Use a per-request, node-local directory (not $CFG->tempdir, which is shared storage)
        // since this file never needs to be seen by other nodes, and is cleaned up automatically
        // on shutdown even if this task does not complete normally.
        $tempdir = make_request_directory();
        $tempfile = tempnam($tempdir, 's3logs_');
        $fp = fopen($tempfile, 'w');

        return  [$tempfile, $fp];
    }

    /**
     * Given a file pointer write the fields from the logstore
     * table as headers.
     *
     * @param resource $fp Valid file pointer
     * @return int $result The Length of the header cotnent written.
     */
    private function write_file_headers($fp) {
        global $DB;

        $headerrecords = $DB->get_columns('logstore_standard_log');
        $headers = [];
        foreach ($headerrecords as $key => $value) {
            $headers[] = $key;
        }
        // Explicitly pass the (previously implicit) default escape character to avoid the
        // PHP 8.4+ deprecation notice about its default value changing in a future version.
        $result = fputcsv($fp, $headers, escape: '\\');

        return $result;
    }

    /**
     * Build SQL condition and params for the course ID filter.
     *
     * Returns an empty string and empty array when no filter is configured.
     *
     * @param object $config Plugin config.
     * @return array [$sql, $params] ready to append to a WHERE clause.
     */
    private function get_course_filter_sql($config): array {
        global $DB;

        $raw = isset($config->courseids) ? trim($config->courseids) : '';
        if ($raw === '') {
            return ['', []];
        }

        // Split, trim, and keep only strictly numeric tokens to avoid accidentally
        // targeting course 0 (e.g. "1,2,3,abc" must not become "1,2,3,0").
        $tokens = array_map('trim', explode(',', $raw));
        $ids = array_map('intval', array_filter($tokens, 'ctype_digit'));

        if (empty($ids)) {
            return ['', []];
        }

        $mode = isset($config->coursefiltermode) ? $config->coursefiltermode : 'include';
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_QM, 'param', $mode !== 'exclude');

        return [" AND courseid $insql", $inparams];
    }

    /**
     * Find the oldest eligible log record's timecreated, used as the start of the overall
     * backlog being archived (for reporting, and as the starting point for slicing).
     *
     * Progress is reported based on position within the overall *time* range being archived
     * (from this starttime through to the archive threshold), using slice boundaries that
     * the task itself controls - not on record IDs or content. An ID-based approach was
     * tried previously, but actual record IDs do not reliably increase with timecreated
     * (backdated/imported events, bulk test data, etc. can insert old-dated rows with high
     * IDs), which made progress jump around unpredictably. Slice boundaries, by contrast,
     * always advance monotonically through the time range by construction, so using them
     * for progress is robust regardless of how IDs happen to correlate with timecreated.
     *
     * This is a cheap, indexed MIN(timecreated) query, avoiding the cost of a full COUNT()
     * over the (potentially huge) logstore table.
     *
     * @param int $interval Interval of months in seconds.
     * @param object $config Plugin config.
     * @return ?int Timecreated of the oldest eligible record, or null if there are none.
     */
    private function get_eligible_start_time($interval, $config): ?int {
        global $DB;

        $threshold = time() - $interval;
        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);
        $wheresql = 'timecreated <= ?' . $coursefiltersql;
        $whereparams = array_merge([$threshold], $coursefilterparams);

        $starttime = $DB->get_field_select('logstore_standard_log', 'MIN(timecreated)', $wheresql, $whereparams);

        if ($starttime === false || $starttime === null) {
            // No eligible records.
            return null;
        }

        return (int)$starttime;
    }

    /**
     * Current memory usage as a fraction of the configured PHP memory limit.
     *
     * @return ?float Usage / limit (e.g. 0.42 for 42%), or null if there is no usable limit
     *      to compare against (unlimited, or an invalid/unparsable memory_limit value).
     */
    private static function get_memory_usage_ratio(): ?float {
        $memlimit = ini_get('memory_limit');

        if ($memlimit === false || $memlimit === '-1' || $memlimit === '') {
            // No memory limit.
            return null;
        }

        $reallimit = get_real_size($memlimit);
        if ($reallimit <= 0) {
            // Invalid or unusable configured limit.
            return null;
        }

        return memory_get_usage(true) / $reallimit;
    }

    /**
     * Determines whether the memory usage for self::extract_records() has exceeded the defined threshold.
     *
     * @return bool Returns true when memory usage reaches self::MEMORY_LIMIT_THRESHOLD of memory limit; otherwise false.
     */
    public static function has_memory_exceeded(): bool {
        $ratio = self::get_memory_usage_ratio();

        return $ratio !== null && $ratio >= self::MEMORY_LIMIT_THRESHOLD;
    }

    /**
     * Current memory usage, formatted for display in progress/debug text.
     *
     * Shown as a percentage of the configured memory_limit where one is set (matching what
     * self::has_memory_exceeded() checks against), falling back to an absolute size when
     * there is no usable limit to express it as a percentage of.
     *
     * @return string e.g. "42.3%" or, when unlimited/unparsable, an absolute size e.g. "128MB".
     */
    private static function get_memory_usage_display(): string {
        $ratio = self::get_memory_usage_ratio();

        if ($ratio === null) {
            return display_size(memory_get_usage(true));
        }

        return round($ratio * 100, 1) . '%';
    }

    /**
     * Extract the log records from the db and write
     * to a temporary file.
     *
     * The method is passed an interval of months in seconds,
     * we want to get all records that are older than this
     * number of months.
     *
     * Records are read in ID order, using the ID of the last record read as the
     * cursor for the next query.
     *
     * Progress is reported based on the position of the last processed record's ID within
     * the full range of eligible IDs. Records are extracted in ID order, so this position
     * is guaranteed to increase smoothly as the task runs, unlike the records' timecreated
     * (which only approximately follows ID order, and can jump around or stall the progress
     * bar if backdated/imported events break that assumption). This also avoids needing an
     * expensive record count to know how much work there is to do.
     *
     * @param int $stopat The time to stop process, if there are still records.
     * @param int $interval Interval of months in seconds.
     * @param resource $fp File pointer to temp file to write to.
     * @param object $config Plugin config.
     * @param ?float $percent Progress percentage to report while extracting (held fixed for
     *      the whole slice; see process_slice()), or null to skip progress reporting.
     * @return array $recordids the ID's of the log entries written to the file.
     */
    private function extract_records($stopat, $interval, $fp, $config, ?float $percent = null) {
        global $DB, $CFG;

        $threshold = time() - $interval;
        $recordids = [];
        $lastid = 0;
        $limit = 1000;
        $reportprogress = $this->progress !== null && $percent !== null;

        $this->report_progress('Getting records older than: ' . date('Y-m-d H:i:s', $threshold), $percent);

        [$coursefiltersql, $coursefilterparams] = $this->get_course_filter_sql($config);

        // Get 1000 rows of data from the log table, ordered by ID so that each query can
        // start where the previous one finished. Using the ID as a cursor keeps the cost of
        // every query the same. An increasing OFFSET instead makes the database re-read and
        // discard all of the records already processed, so the task gets progressively
        // slower the longer it runs - and because nothing is deleted until the end of the
        // run, the rows being skipped are still there to be skipped again.
        // Keep getting records 1000 at a time until we run out of records or max execution time is reached.
        while (time() <= $stopat) {
            if (self::has_memory_exceeded()) {
                $memlimitthr = round(self::MEMORY_LIMIT_THRESHOLD * 100, 0);
                // Defer the mtrace until after the progress bar (in execute()) rather than
                // printing it here, so it doesn't mess up the progress bar's output.
                $this->earlyexitmessage = "Memory limit threshold of {$memlimitthr}% reached, "
                    . "stopping processing to avoid an out-of-memory error";
                break;
            }

            if (\core\local\cli\shutdown::should_gracefully_exit()) {
                // Defer the mtrace until after the progress bar (in execute()) rather than
                // printing it here, so it doesn't mess up the progress bar's output.
                $this->earlyexitmessage = 'Interrupt signal received, stopping processing for this run';
                break;
            }

            $records = $DB->get_recordset_select(
                'logstore_standard_log',
                'id > ? AND timecreated <= ?' . $coursefiltersql,
                array_merge([$lastid, $threshold], $coursefilterparams),
                'id ASC',
                '*',
                0,
                $limit
            );

            // We do not want to load all results into memory,
            // we want to write them to a file as we go.
            $count = 0;
            $lasttimecreated = null;
            foreach ($records as $record) {
                // Cast to int: DB layers return numeric fields as strings, but IDs are used
                // as ints elsewhere (e.g. build_keyname()'s typed params, and comparisons
                // against other int-typed IDs such as those from insert_record()).
                $recordids[] = (int)$record->id;
                $lastid = (int)$record->id;
                // Explicitly pass the (previously implicit) default escape character to avoid
                // the PHP 8.4+ deprecation notice about its default value changing in future.
                fputcsv($fp, (array)$record, escape: '\\');
                $lasttimecreated = $record->timecreated;
                $count++;
            }
            $records->close();

            // Percent is fixed for the whole slice (see process_slice()/execute()), based on
            // this slice's position within the overall time range being archived - not on
            // record IDs or content, which don't reliably correlate with timecreated.
            if ($reportprogress && $lasttimecreated !== null) {
                $progresstext = get_string('progress_extracted', 'tool_s3logs', date('Y-m-d H:i:s', $lasttimecreated));

                if (!empty($CFG->debugdeveloper)) {
                    // Debugging only: suffix the progress text (rather than a separate mtrace)
                    // with memory usage, so it doesn't garble the progress bar's own output.
                    $progresstext .= ' (' . get_string('progress_memory', 'tool_s3logs', self::get_memory_usage_display()) . ')';
                }

                $this->progress->update_full($percent, $progresstext);
            }

            if ($count < $limit) {
                break; // Stop trying to get records when we run out.
            }
        }

        if ($this->earlyexitmessage === null && time() > $stopat) {
            // Defer the mtrace until after the progress bar (in execute()) rather than
            // printing it here, so it doesn't mess up the progress bar's output.
            $this->earlyexitmessage = 'WARNING: Maximum run time of ' . $config->maxruntime
                . 's reached, stopping processing for this run';
        }

        return $recordids;
    }

    /**
     * Build the S3 object key for an archived batch of records.
     *
     * Normally {prefix}_{date}_{first}_{last}.csv, but the leading underscore is
     * omitted when no prefix is configured, e.g. {date}_{first}_{last}.csv. The date
     * is in ISO 8601 date format (Y-m-d), and is the timecreated of the earliest
     * (oldest) record in the batch, not the time the task ran. The time-of-day
     * component is omitted (events are batched by day, and it avoids colons in the key).
     *
     * @param string $prefix Configured S3 key prefix, may be empty.
     * @param int $earliesttimecreated Timecreated of the earliest (oldest) record in the batch.
     * @param int $firstrecord ID of the first (oldest) record in the batch.
     * @param int $lastrecord ID of the last (newest) record in the batch.
     * @return string
     */
    private function build_keyname(string $prefix, int $earliesttimecreated, int $firstrecord, int $lastrecord): string {
        $parts = array_filter(
            [$prefix, date('Y-m-d', $earliesttimecreated), $firstrecord, $lastrecord],
            fn($part) => $part !== ''
        );

        return implode('_', $parts) . '.csv';
    }

    /**
     * Reports a progress message, either by updating the stored progress bar in place
     * (holding it at $percent, just changing its message) or via plain mtrace if there is
     * no progress bar to update.
     *
     * @param string $message The message to report.
     * @param ?float $percent Percentage to hold the progress bar at, or null to use mtrace.
     */
    private function report_progress(string $message, ?float $percent): void {
        if ($percent !== null) {
            $this->progress->update_full($percent, $message);
        } else {
            mtrace($message);
        }
    }

    /**
     * Deletes rows from teh log store table.
     *
     * @param array $recordids Array of record ID's to delete
     */
    private function delete_records($recordids) {
        global $DB;

        $chunks = array_chunk($recordids, 1000, true);
        foreach ($chunks as $chunk) {
            $DB->delete_records_list('logstore_standard_log', 'id', $chunk);
        }
    }

    /**
     * Issues a VACUUM on logstore_standard_log to reclaim dead tuple space.
     *
     * Only executed on PostgreSQL and only when the vacuum_after_delete setting
     * is enabled. Called after a successful batch delete to prevent autovacuum
     * from falling behind during aggressive archiving campaigns.
     *
     * @param object $config Plugin config.
     * @param ?float $percent Progress percentage to hold the progress bar at while vacuuming
     *                        (just updates its message, doesn't advance it), or null to fall
     *                        back to plain mtrace when there is no progress bar to update.
     */
    private function vacuum_logstore($config, ?float $percent = null): void {
        global $DB;

        if (empty($config->vacuum_after_delete)) {
            return;
        }

        if ($DB->get_dbfamily() !== 'postgres') {
            return;
        }

        try {
            // Reported immediately as it starts (rather than afterwards with elapsed time):
            // it just holds the progress bar at a fixed percentage, so there's nothing useful
            // to show after the fact.
            $this->report_progress(get_string('progress_vacuuming', 'tool_s3logs'), $percent);

            $DB->execute('VACUUM {logstore_standard_log}');
        } catch (\dml_exception $e) {
            mtrace('WARNING: VACUUM on logstore_standard_log failed: ' . $e->getMessage());
        }
    }

    /**
     * Determine the end boundary (timecreated cutoff) of the next slice to process.
     *
     * Uses the task's own cron schedule to infer how large a slice should be: starting from
     * the given slice start, find when this task would next have run, and use that as the
     * end of the slice, capped to the overall archive threshold ($rangeend). This means a
     * large backlog gets broken up into files of a similar shape (e.g. one per day/week) to
     * how the task behaves once it has caught up and is running regularly.
     *
     * If the schedule's steady-state period is shorter than self::MIN_SLICE_SECONDS (e.g. an
     * hourly task), slices fall back to whole calendar days instead: otherwise, clearing a
     * large backlog on a frequently-run task would produce an excessive number of small files
     * (e.g. one per hour) rather than a sensible number of (e.g.) daily ones. The period is
     * measured between two consecutive scheduled times (rather than from $slicestart itself,
     * which may sit at an arbitrary offset into the schedule's cycle and so could understate
     * a schedule that genuinely runs only about once a day).
     *
     * @param int $slicestart Start (timecreated) of the slice being sized.
     * @param int $rangeend The overall archive threshold; slices never extend past this.
     * @return int The end (timecreated) of the slice, guaranteed to be > $slicestart.
     */
    private function get_slice_cutoff(int $slicestart, int $rangeend): int {
        $nextscheduled = $this->get_next_scheduled_time($slicestart);

        $followingscheduled = $this->get_next_scheduled_time($nextscheduled);
        $period = $followingscheduled - $nextscheduled;

        if ($period > 0 && $period < self::MIN_SLICE_SECONDS) {
            // Sub-daily schedule: use the end of the current calendar day instead of the
            // next scheduled run, so backlog processing produces daily (not hourly) files.
            $nextscheduled = max($nextscheduled, strtotime('midnight +1 day', $slicestart));
        }

        $slicecutoff = min($nextscheduled, $rangeend);

        if ($slicecutoff <= $slicestart) {
            // Safety net: guarantee forward progress even if the schedule can't advance
            // (e.g. misconfigured cron fields), to avoid looping forever.
            $slicecutoff = $rangeend;
        }

        return $slicecutoff;
    }

    /**
     * Process a single slice of the backlog: extract eligible records up to $slicecutoff,
     * upload them to S3 (one file per slice) and delete them from the log store.
     *
     * Splitting the backlog into slices means that when there is a large backlog to clear
     * (e.g. the task has just been enabled on a site with years of existing logs), the
     * resulting files end up the same shape as they would be once the task has caught up
     * and is running regularly (e.g. one file per day/week), rather than one huge file
     * covering the whole backlog.
     *
     * @param int $stopat The time to stop processing, if there are still records.
     * @param int $slicecutoff Upper bound (timecreated) of records to process in this slice.
     * @param object $config Plugin config.
     * @param ?float $percent Progress percentage this slice represents (position of
     *      $slicecutoff within the overall time range being archived), or null to skip
     *      progress reporting.
     * @return int The number of records processed in this slice.
     */
    private function process_slice($stopat, $slicecutoff, $config, ?float $percent): int {
        global $DB;

        // Get a temp file and write the headers to it.
        [$tempfile, $fp] = $this->get_temp_file();
        $headerwrite = $this->write_file_headers($fp);
        if (!$headerwrite) {
            throw new \moodle_exception('noheaders', 'tool_s3logs', '');
        }

        try {
            // Extract records from DB and add them to the temp file. The interval is derived
            // from the slice's cutoff (rather than the configured max log age) so that only
            // records up to this slice's boundary are extracted.
            $starttime = microtime(true);
            $sliceinterval = time() - $slicecutoff;
            $recordids = $this->extract_records($stopat, $sliceinterval, $fp, $config, $percent);
            fclose($fp); // Close file now that we have it.

            // Deferred from extract_records() til immediately after the progress bar, so
            // it doesn't mess up its output, but before anything else happens.
            if ($this->earlyexitmessage !== null) {
                mtrace($this->earlyexitmessage);
            }

            if (empty($recordids)) {
                $this->report_progress(get_string('progress_slice_empty', 'tool_s3logs'), $percent);
                return 0;
            }

            $numrecords = count($recordids);
            $firstrecord = min($recordids);
            $lastrecord = max($recordids);
            // Use the actual earliest record's timecreated (rather than the slice's nominal
            // start boundary) so the keyname reflects the real contents of this batch.
            $earliesttimecreated = (int)$DB->get_field('logstore_standard_log', 'timecreated', ['id' => $firstrecord]);
            $keyname = $this->build_keyname($config->prefix, $earliesttimecreated, $firstrecord, $lastrecord);

            $this->report_progress(get_string('progress_extraction_complete', 'tool_s3logs', (object)[
                'count' => $numrecords,
                'tempfile' => $tempfile,
                'elapsed' => sprintf('%.1f', microtime(true) - $starttime),
            ]), $percent);

            // Report each step's message immediately as it starts, rather than afterwards
            // with its elapsed time: upload/delete/vacuum hold the progress bar at a fixed
            // percentage anyway (they don't advance it), so there is nothing useful to show
            // after the fact - just what is currently happening.
            $this->report_progress(get_string('progress_uploading', 'tool_s3logs', (object)[
                'count' => $numrecords,
                'keyname' => $keyname,
            ]), $percent);

            $s3client = new s3_client();
            $s3url = $s3client->upload_file($tempfile, $keyname);

            if (!$s3url) {
                throw new \moodle_exception('s3uploadfailed', 'tool_s3logs', '');
            }

            // Delete the processed records from the log table.
            $this->report_progress(get_string('progress_deleting', 'tool_s3logs', $numrecords), $percent);

            $this->delete_records($recordids);

            $this->vacuum_logstore($config, $percent);

            return $numrecords;
        } finally {
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
    }

    /**
     * {@inheritDoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $config = get_config('tool_s3logs');

        if (empty($config->enable)) {
            mtrace('Log archive tasks are disabled.');
            return;
        }

        // Set up basic vars.
        $maxage = (int)$config->maxlogage; // Stored in seconds via admin_setting_configduration.
        $stopat = time() + $config->maxruntime;
        $rangeend = time() - $maxage; // The archive threshold ("time we are archiving up to").

        // Find the start of the eligible records (for the keyname/status messages and as the
        // starting point for slicing), rather than running an expensive COUNT() of matching
        // records.
        mtrace(get_string('progress_finding', 'tool_s3logs'));
        $starttime = $this->get_eligible_start_time($maxage, $config);

        if ($starttime === null) {
            mtrace(get_string('progress_norecords', 'tool_s3logs'));
            return;
        }

        $this->start_stored_progress();

        $progressrange = (object)[
            'start' => date('Y-m-d H:i:s', $starttime),
            'end' => date('Y-m-d H:i:s', $rangeend),
        ];
        $this->progress->update_full(0, get_string('progress_archiving', 'tool_s3logs', $progressrange));

        // Process the backlog in slices based on the task's own cron schedule: starting at
        // the oldest eligible record, find when this task would next have run after that
        // point, and use that as the end of the current slice. This way, working through a
        // large backlog produces files of a similar shape (e.g. one per day/week) to how the
        // task behaves once it has caught up, rather than one single huge file.
        //
        // Progress is reported based on each slice's position within the overall time range
        // being archived (not on record IDs/content, which don't reliably correlate with
        // timecreated - see get_eligible_start_time()), so it advances smoothly across the
        // whole run regardless of how many slices there are.
        $slicestart = $starttime;
        $totalrange = max($rangeend - $starttime, 1);
        $totalprocessed = 0;

        while (true) {
            if (time() > $stopat) {
                $this->earlyexitmessage = 'WARNING: Maximum run time of ' . $config->maxruntime
                    . 's reached, stopping processing for this run';
                break;
            }

            if (self::has_memory_exceeded()) {
                $memlimitthr = round(self::MEMORY_LIMIT_THRESHOLD * 100, 0);
                $this->earlyexitmessage = "Memory limit threshold of {$memlimitthr}% reached, "
                    . "stopping processing to avoid an out-of-memory error";
                break;
            }

            if (\core\local\cli\shutdown::should_gracefully_exit()) {
                $this->earlyexitmessage = 'Interrupt signal received, stopping processing for this run';
                break;
            }

            // Find when this task would next run after the start of this slice, capped to
            // the overall archive threshold (we must never process records newer than that).
            $slicecutoff = $this->get_slice_cutoff($slicestart, $rangeend);
            $slicepercent = min(100, max(0, (($slicecutoff - $starttime) / $totalrange) * 100));

            $this->report_progress(get_string('progress_slice', 'tool_s3logs', (object)[
                'start' => date('Y-m-d H:i:s', $slicestart),
                'end' => date('Y-m-d H:i:s', $slicecutoff),
            ]), $slicepercent);

            $totalprocessed += $this->process_slice($stopat, $slicecutoff, $config, $slicepercent);

            if ($this->earlyexitmessage !== null) {
                // extract_records() hit the time/memory/interrupt limit partway through this
                // slice; stop entirely rather than starting another slice.
                break;
            }

            if ($slicecutoff >= $rangeend) {
                // Reached the overall archive threshold: nothing older is eligible, so there
                // is nothing more to do this run.
                $finalmessage = $totalprocessed > 0
                    ? get_string('progress_complete', 'tool_s3logs', $totalprocessed)
                    : get_string('progress_norecords', 'tool_s3logs');
                $this->progress->update_full(100, $finalmessage);
                break;
            }

            $slicestart = $slicecutoff;
        }
    }
}
