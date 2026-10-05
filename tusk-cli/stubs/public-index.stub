<?php

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;

require __DIR__.'/../vendor/autoload.php';

$application = require __DIR__.'/../bootstrap/app.php';
$normalizeUpload = static function (array $file) use (&$normalizeUpload) {
    $tmpName = $file['tmp_name'] ?? null;

    if (is_array($tmpName)) {
        $uploads = [];
        foreach ($tmpName as $key => $nestedTmpName) {
            $uploads[$key] = $normalizeUpload([
                'name' => $file['name'][$key] ?? null,
                'type' => $file['type'][$key] ?? null,
                'tmp_name' => $nestedTmpName,
                'error' => $file['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$key] ?? 0,
            ]);
        }

        return $uploads;
    }

    return new UploadedFile(
        (string) ($tmpName ?? ''),
        (int) ($file['size'] ?? 0),
        (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        $file['name'] ?? null,
        $file['type'] ?? null,
    );
};
$uploadedFiles = [];
foreach ($_FILES as $field => $file) {
    $uploadedFiles[$field] = $normalizeUpload($file);
}
$request = new ServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    function_exists('getallheaders') ? getallheaders() : [],
    file_get_contents('php://input'),
    '1.1',
    $_SERVER,
);
$request = $request
    ->withParsedBody($_POST)
    ->withCookieParams($_COOKIE)
    ->withUploadedFiles($uploadedFiles);
$response = $application->handle($request);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name.': '.$value, false);
    }
}
echo $response->getBody();
