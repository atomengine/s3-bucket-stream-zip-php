<?php
/**
 * @author Jaisen Mathai <jaisen@jmathai.com>
 * @copyright Copyright 2015, Jaisen Mathai
 *
 * This library streams the contents from an Amazon S3 bucket
 *  without needing to store the files on disk or download
 *  all of the files before starting to send the archive.
 *
 * Example usage can be found in the examples folder.
 */

namespace AtomEngine\S3BucketStreamZip;

use AtomEngine\S3BucketStreamZip\Exception\InvalidParameterException;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

use ZipStream;

class S3BucketStreamZip
{
    /**
     * @var array
     *
     * See the documentation for the List Objects API for valid parameters.
     * Only `Bucket` is required.
     *
     * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTBucketGET.html
     *
     * {
     *   Bucket: name_of_bucket
     * }
     */
    private $params = array();

    /**
     * @var S3Client
     */
    private $s3Client;

    /**
     * Create a new ZipStream object.
     *
     * @param array $auth - AWS key and secret
     * @param array $params - AWS List Object parameters
     * @throws InvalidParameterException
     */
    public function __construct($params)
    {
        // We require the AWS key to be passed in $auth.
        if (!isset($params['key']))
            throw new InvalidParameterException('$auth parameter to constructor requires a `key` attribute');

        // We require the AWS secret to be passed in $auth.
        if (!isset($params['secret']))
            throw new InvalidParameterException('$auth parameter to constructor requires a `secret` attribute');

        // We require the AWS S3 bucket to be passed in $params.
        if (!isset($params['Bucket']))
            throw new InvalidParameterException('$params parameter to constructor requires a `Bucket` attribute (with a capital B)');

        // We require the AWS S3 region to be passed in $params.
        if (!isset($params['region']))
            throw new InvalidParameterException('$params parameter to constructor requires a `region` attribute');

        $params['version'] = "2006-03-01";

        $this->params = $params;

        $this->s3Client = new S3Client($this->params);
    }

    /**
     * Stream a zip file to the client
     *
     * @param String $filename - Name for the file to be sent to the client
     * @param array $params - Optional parameters
     *  {
     *    expiration: '+10 minutes'
     *  }
     */
    public function send($filename, $params = array())
    {
        // Set default values for the optional $params argument
        if (!isset($params['expiration']))
            $params['expiration'] = '+6 hours';

        $options = new ZipStream\Option\Archive();
        $options->setSendHttpHeaders(true);
        // Initialize the ZipStream object and pass in the file name which
        //  will be what is sent in the content-disposition header.
        // This is the name of the file which will be sent to the client.
        $zip = new ZipStream\ZipStream($filename,$options);

        // Get a list of objects from the S3 bucket. The iterator is a high
        //  level abstration that will fetch ALL of the objects without having
        //  to manually loop over responses.
        $result = $this->s3Client->getIterator('ListObjects', $this->params);

        // We loop over each object from the ListObjects call.
        foreach ($result as $file) {
            // We need to use a command to get a request for the S3 object
            //  and then we can get the presigned URL.
            $command = $this->s3Client->getCommand('GetObject', array(
                'Bucket' => $this->params['Bucket'],
                'Key' => $file['Key']
            ));
            $response = $this->s3Client->createPresignedRequest($command, "+1 week");
            $signedUrl = (string)$response->getUri();
            // Get the file name on S3 so we can save it to the zip file
            //  using the same name.
            $fileName = basename($file['Key']);

            // We want to fetch the file to a file pointer so we create it here
            //  and create a curl request and store the response into the file
            //  pointer.
            // After we've fetched the file we add the file to the zip file using
            //  the file pointer and then we close the curl request and the file
            //  pointer.
            // Closing the file pointer removes the file.
            $fp = tmpfile();
            $ch = curl_init($signedUrl);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fseek($fp, 0);
            $zip->addFileFromStream($fileName, $fp);
            fclose($fp);
        }

        // Finalize the zip file.
        $zip->finish();
    }
}
