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

namespace tool_s3logs\local\client;

use Aws\Command;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

/**
 * S3 Client helper class.
 *
 * @package     tool_s3logs
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class s3_client {
    /**
     * Files at or above this size, in bytes, are uploaded using multipart upload.
     */
    const MULTIPART_THRESHOLD = 104857600;

    /**
     * Size, in bytes, of each part of a multipart upload.
     */
    const MULTIPART_PART_SIZE = 16777216;

    /**
     * Number of parts of a multipart upload to send at a time.
     */
    const MULTIPART_CONCURRENCY = 4;

    /**
     * Plugin config.
     * @var false|mixed|object|string
     */
    private $config;

    /**
     * Client.
     * @var \Aws\AwsClientInterface|null
     */
    private $client = null;

    /**
     * Constructor for S3 client class.
     *
     * Makes relevant config available and bootstraps AWS S3 client.
     *
     * @return void
     */
    public function __construct() {
        $this->config = get_config('tool_s3logs');
        $this->set_client();
    }

    /**
     * Check if the client is functional.
     * @return bool
     */
    private function is_functional(): bool {
        return isset($this->client);
    }

    /**
     * Check if the client configured properly.
     * @return bool
     */
    public function is_configured(): bool {
        if (empty($this->config->bucket) || empty($this->config->s3region)) {
            return false;
        }

        if (empty($this->config->usesdkcreds) && (empty($this->config->keyid) || empty($this->config->secretkey))) {
            return false;
        }

        return true;
    }

    /**
     * Sets AWS S3 client.
     */
    private function set_client() {
        if (!$this->is_configured()) {
            $this->client = null;
        } else {
            // Use a short connection timeout so status checks fail fast without affecting long-running uploads.
            $settings = [
                'region' => $this->config->s3region,
                'version' => 'latest',
                'http' => [
                    'connect_timeout' => 5,
                ],
            ];

            if (!$this->config->usesdkcreds) {
                $settings['credentials'] = ['key' => $this->config->keyid, 'secret' => $this->config->secretkey];
            }

            $this->client = new S3Client($settings);
        }
    }

    /**
     * Uploads a temp local file to s3.
     *
     * If the upload operation fails the parent AWS client lib will throw an error.
     * This won't fail silently.
     *
     * Large files are sent as a multipart upload, which uploads several parts of
     * the file at a time and is not subject to the 5GB limit of a single PUT.
     *
     * @param string $filepath The path to the temp file.
     * @param string $keyname The nbame to give the object in S3.
     * @param string $contenttype The content type to give the object in S3.
     * @return string|null $s3url The URL to the object in S3
     */
    public function upload_file(string $filepath, string $keyname, string $contenttype = 'text/csv'): ?string {
        $s3url = null;

        if ($this->is_functional()) {
            if (filesize($filepath) >= self::MULTIPART_THRESHOLD) {
                $result = $this->upload_file_multipart($filepath, $keyname, $contenttype);
            } else {
                $result = $this->client->putObject([
                    'Bucket' => $this->config->bucket,
                    'Key' => $keyname,
                    'SourceFile' => $filepath,
                    'ContentType' => $contenttype,
                ]);
            }

            // Completing a multipart upload returns the object URL as Location.
            $s3url = $result['ObjectURL'] ?? $result['Location'] ?? null;
        }

        return $s3url;
    }

    /**
     * Uploads a local file to s3 as a multipart upload.
     *
     * @param string $filepath The path to the temp file.
     * @param string $keyname The name to give the object in S3.
     * @param string $contenttype The content type to give the object in S3.
     * @return \Aws\Result The result of completing the upload.
     */
    private function upload_file_multipart(string $filepath, string $keyname, string $contenttype) {
        $uploader = new MultipartUploader($this->client, $filepath, [
            'bucket' => $this->config->bucket,
            'key' => $keyname,
            'part_size' => self::MULTIPART_PART_SIZE,
            'concurrency' => self::MULTIPART_CONCURRENCY,
            // The content type only applies to the object as a whole, so it has to be
            // set on the request that starts the upload rather than passed as a param,
            // which the SDK would also apply to each UploadPart request.
            'before_initiate' => function (Command $command) use ($contenttype) {
                $command['ContentType'] = $contenttype;
            },
        ]);

        return $uploader->upload();
    }

    /**
     * Returns a status message for the client.
     *
     * @return string
     */
    public function get_client_status_message(): string {
        global $OUTPUT;

        $output = '';
        $connection = $this->test_connection();
        if ($connection->success) {
            $output .= $OUTPUT->notification(get_string('connectionsuccess', 'tool_s3logs'), 'success');
            $output .= $this->get_permissions_check_status();
        } else {
            $output .= $OUTPUT->notification(get_string('connectionfailure', 'tool_s3logs', $connection->details), 'error');
        }

        return $output;
    }

    /**
     * Perform test connection and permission check using the default credential provider chain to find AWS credentials.
     *
     * @return string
     */
    public function get_sdk_credentials_status(): string {
        global $OUTPUT;
        $output = '';

        if (empty($this->config->usesdkcreds)) {
            // Emulate using default credentials to check the connection.
            $this->config->usesdkcreds = 1;
            $this->set_client();

            $connection = $this->test_connection();
            if ($connection->success) {
                $output .= $OUTPUT->notification(get_string('sdkcredsok', 'tool_s3logs'), 'success');
                $output .= $this->get_permissions_check_status();
            } else {
                $output .= $OUTPUT->notification(get_string('sdkcredserror', 'tool_s3logs'), 'warning');
            }

            // Revert the client.
            $this->config->usesdkcreds = 0;
            $this->set_client();
        }

        return $output;
    }

    /**
     * Returns a status of the permissions check.
     *
     * @return string
     */
    private function get_permissions_check_status(): string {
        global $OUTPUT;

        $output = '';
        $permissions = $this->test_permissions();
        if ($permissions->success) {
            $output .= $OUTPUT->notification(key($permissions->messages), 'success');
        } else {
            foreach ($permissions->messages as $message => $type) {
                $output .= $OUTPUT->notification($message, $type);
            }
        }

        return $output;
    }

    /**
     * Tests connection to S3 and bucket.
     *
     * @return object
     */
    public function test_connection() {
        $connection = new \stdClass();
        $connection->success = true;
        $connection->details = '';

        try {
            if (!$this->is_functional()) {
                $connection->success = false;
                $connection->details = get_string('notconfigured', 'tool_s3logs');
            } else {
                $this->client->headBucket(['Bucket' => $this->config->bucket]);
            }
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $connection->success = false;
            $connection->details = $this->get_exception_details($e);
        } catch (\GuzzleHttp\Exception\InvalidArgumentException $e) {
            $connection->success = false;
            $connection->details = $this->get_exception_details($e);
        } catch (\Aws\Exception\CredentialsException $e) {
            $connection->success = false;
            $connection->details = $this->get_exception_details($e);
        }

        return $connection;
    }

    /**
     * Tests permissions to write to S3 bucket.
     *
     * @return object
     */
    public function test_permissions() {
        $permissions = new \stdClass();

        $permissions->success = true;
        $permissions->messages = [];

        if (!$this->is_functional()) {
            $permissions->success = false;
            $permissions->messages = [];
        } else {
            try {
                $result = $this->client->putObject([
                    'Bucket' => $this->config->bucket,
                    'Key' => 'permissions_check_file',
                    'Body' => 'test upload content',
                ]);
            } catch (\Aws\S3\Exception\S3Exception $e) {
                $details = $this->get_exception_details($e);
                $permissions->messages[get_string('writefailure', 'tool_s3logs', $details)] = 'error';
                $permissions->success = false;
            }

            if ($permissions->success) {
                $permissions->messages[get_string('permissioncheckpassed', 'tool_s3logs')] = 'success';
            }
        }

        return $permissions;
    }

    /**
     * Get details from the given exception.
     *
     * @param \Exception $exception Exception to get details from.
     * @return string
     */
    private function get_exception_details(\Exception $exception): string {

        if (get_class($exception) !== '\Aws\S3\Exception\S3Exception') {
            $details = "Not a S3 exception : " . $exception->getMessage();
        } else {
            $details = ' ';
            $message = $exception->getMessage();
            $errorcode = $exception->getAwsErrorCode();

            if ($message) {
                $details .= "ERROR MSG: " . $message . "\n";
            }

            if ($errorcode) {
                $details .= "ERROR CODE: " . $errorcode . "\n";
            }
        }

        return $details;
    }
}
