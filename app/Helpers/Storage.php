<?php

namespace App\Helpers;

use Log;
use GuzzleHttp;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Ftp as Adapter;

class Storage
{

    private $local_path;

    public function __construct($doc)
    {
        $this->doc = $doc;
    }

    public function uploadS3($presigned_url)
    {
        Log::info("Uploading invoice to S3 => " . $presigned_url);

        try {
            $client = new GuzzleHttp\Client();
            $res = $client->request('PUT', $presigned_url, [
              'multipart' => [
                  [
                    'name' => 'file',
                    'contents' => $this->doc,
                  ],
              ],
              'timeout' => config('services.s3.timeout'),
            ]);
        } catch (GuzzleHttp\Exception\RequestException $err) {
            $reason = NULL;
            $message = NULL;
            if ($err->hasResponse()) {
                $message = \GuzzleHttp\Psr7\str($err->getResponse());
                Log::error($message);
                $message = explode("\r\n", $message);
                $reason = $err->getResponse()->getReasonPhrase();
            }
            return [
                'uploaded' => false,
                'reason' => $reason,
                'message' => $message,
            ];
        }

        return [
            'uploaded' => true,
            'path' => preg_replace('/\?.*/', '', $presigned_url),
        ];
    }


    /**
     * [uploadFTP description]
     * @param  array $FTPcredentials The credentials for the FTP server
     * @return array                 Upload's result
     */
    public function uploadFTP($FTPcredentials, $invoiceId) {
        // TODO: check if path has trailing slash and add it automatically if its not there
        // TODO: include ssl parameter and default it to false
        // TODO: include passive parameter and default it to true
        // TODO: make tests in case path does not exist (the ROOT param for the adapter)


        if (ends_with($FTPcredentials["path"], "/") == false) {
            $FTPcredentials["path"] = $FTPcredentials["path"] . "/";
        }

        $adapter = new Adapter([
            'host' => $FTPcredentials["host"],
            'username' => $FTPcredentials["username"],
            'password' => $FTPcredentials["password"],
            'port' => isset($FTPcredentials["port"]) ? $FTPcredentials["port"] : 21,
            'root' => $FTPcredentials["path"],
            'passive' => isset($FTPcredentials["passive"]) ? $FTPcredentials["passive"] : true,
            'ssl' => isset($FTPcredentials["ssl"]) ? $FTPcredentials["ssl"] : false,
            'timeout' => 300
        ]);

        try {
            $adapter->getConnection();
        } catch (\RuntimeException $e) {
            return [
                'uploaded' => false,
                'message' => $e->getMessage(),
                'path' => null,
            ];
        }

        $filesystem = new Filesystem($adapter);

        $filePath = "invoice_". $invoiceId ."_" . time() .".pdf";

        $response = $filesystem->write($filePath , $this->doc, ['visibility' => 'public']);
        if (!$response) {
            return [
                'uploaded' => false,
                'message' => "The invoice could not be uploaded to the FTP server.",
                'path' => null
            ];
        }

        return [
            'uploaded' => true,
            'message' => null,
            'path' => $FTPcredentials["path"] . $filePath
        ];


    }

}
