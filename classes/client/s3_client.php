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
 * S3 Client helper class.
 *
 * @package     tool_s3logs
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_s3logs\client;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/aws/sdk/aws-autoloader.php');

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
     * Constructor for S3 client class.
     * Makes relevant config available and bootstraps
     * AWS S3 client.
     *
     * @return void
     */
    public function __construct() {
        $this->config = get_config('tool_s3logs');
        $this->s3region = $this->config->s3region;
        $this->keyid = $this->config->keyid;
        $this->secretkey = $this->config->secretkey;
        $this->bucket = $this->config->bucket;
        $this->client = $this->get_s3_client();
    }

    /**
     * Create AWS S3 client.
     *
     * @return client $s3client S3 client.
     */
    private function get_s3_client() {
        $s3client = S3Client::factory ( array (
                'credentials' => array (
                        'key' => $this->keyid,
                        'secret' => $this->secretkey
                ),
                'region' => $this->s3region,
                'version' => 'latest'
        ) );
        return $s3client;
    }

    /**
     * Uploads a temp local file to s3.
     * If the uipload operation fails the parent
     * AWS client lib will throw an error.
     * This won't fail silently.
     *
     * @param string $filepath The path to the temp file.
     * @param string $keyname The nbame to give the object in S3.
     * @return string $s3url The URL to the object in S3
     */
    public function upload_file($filepath, $keyname) {
        $s3client = $this->client;
        $s3url = false;
        $result = $s3client->putObject(array(
                'Bucket'       => $this->bucket,
                'Key'          => $keyname,
                'SourceFile'   => $filepath,
                'ContentType'  => 'text/csv'

        ));
        $s3url = $result['ObjectURL'];

        return $s3url;
    }

    /**
     * Downloads a file from s3 to supplied path.
     *
     * @param string $filepath Local file path.
     * @param string $keyname  S3 keyname.
     * @return void
     */
    public function download_file($filepath, $keyname) {
        $result = $this->client->getObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $keyname,
            'SaveAs' => $filepath
        ));
    }

    /**
     * Returns all objects keys in the bucket.
     *
     * @return array
     */
    public function get_all_keys() {
        // We use the iteratior incase there are more than 1000.
        $keys = array();
        $objects = $this->client->getIterator('ListObjects', array('Bucket' => $this->bucket));
        foreach ($objects as $object) {
            $keys[] = $object['Key'];
        }
        return $keys;
    }
}