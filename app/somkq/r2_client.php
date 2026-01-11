<?php
/**
 * Cloudflare R2 Storage Client (S3-Compatible)
 * 文件路径: app/somkq/r2_client.php
 * 说明: 轻量级R2存储客户端，使用流式上传避免内存溢出
 */

// 防止直接访问
if (!defined('IN_APP')) {
    exit('Access Denied');
}

class R2Client {
    private $accountId;
    private $accessKeyId;
    private $secretAccessKey;
    private $bucketName;
    private $endpoint;
    private $region;

    /**
     * 构造函数
     */
    public function __construct($accountId, $accessKeyId, $secretAccessKey, $bucketName, $region = 'auto') {
        $this->accountId = $accountId;
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->bucketName = $bucketName;
        $this->region = $region;
        $this->endpoint = "https://{$accountId}.r2.cloudflarestorage.com";
    }

    /**
     * 上传文件到R2 (使用流式上传，低内存占用)
     * @param string $key 对象键（路径）
     * @param string $filePath 本地文件路径
     * @param string $contentType MIME类型
     * @return array ['success' => bool, 'message' => string, 'etag' => string|null]
     */
    public function putObject($key, $filePath, $contentType = 'application/octet-stream') {
        try {
            if (!file_exists($filePath)) {
                return ['success' => false, 'message' => 'File not found', 'etag' => null];
            }

            $fileSize = filesize($filePath);

            // 使用流式哈希计算，避免将整个文件读入内存
            $contentMD5 = base64_encode(md5_file($filePath, true));
            $contentSha256 = hash_file('sha256', $filePath);

            $url = "{$this->endpoint}/{$this->bucketName}/{$key}";
            $timestamp = gmdate('Ymd\THis\Z');

            // 构建请求头
            $headers = [
                'Content-Type' => $contentType,
                'Content-Length' => $fileSize,
                'Content-MD5' => $contentMD5,
                'x-amz-date' => $timestamp,
                'x-amz-content-sha256' => $contentSha256,
            ];

            // AWS Signature V4 - 传递预计算的Hash
            $signature = $this->signRequest('PUT', $key, $headers, $contentSha256, $timestamp);
            $headers['Authorization'] = $signature;

            // 打开文件句柄进行流式上传
            $fp = fopen($filePath, 'r');
            if (!$fp) {
                throw new Exception("无法打开文件进行读取: $filePath");
            }

            // 发送请求
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,          // 启用上传模式
                CURLOPT_INFILE => $fp,           // 设定输入文件句柄
                CURLOPT_INFILESIZE => $fileSize, // 设定文件大小
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            // 关闭资源
            curl_close($ch);
            fclose($fp);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Upload successful',
                    'etag' => null
                ];
            } else {
                error_log("R2 upload failed: HTTP $httpCode - $response");
                return [
                    'success' => false,
                    'message' => "Upload failed: HTTP $httpCode",
                    'etag' => null,
                    'response' => $response
                ];
            }
        } catch (Exception $e) {
            error_log("R2 upload exception: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'etag' => null];
        }
    }

    /**
     * 删除R2中的对象
     */
    public function deleteObject($key) {
        try {
            $url = "{$this->endpoint}/{$this->bucketName}/{$key}";
            $timestamp = gmdate('Ymd\THis\Z');

            // 空负载的Hash
            $emptyPayloadHash = hash('sha256', '');

            $headers = [
                'x-amz-date' => $timestamp,
                'x-amz-content-sha256' => $emptyPayloadHash,
            ];

            $signature = $this->signRequest('DELETE', $key, $headers, $emptyPayloadHash, $timestamp);
            $headers['Authorization'] = $signature;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 204 || $httpCode === 200) {
                return ['success' => true, 'message' => 'Delete successful'];
            } else {
                error_log("R2 delete failed: HTTP $httpCode - $response");
                return ['success' => false, 'message' => "Delete failed: HTTP $httpCode"];
            }
        } catch (Exception $e) {
            error_log("R2 delete exception: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取签名URL
     */
    public function getPresignedUrl($key, $expiresIn = 300) {
        try {
            $timestamp = time();
            $expiration = $timestamp + $expiresIn;
            $date = gmdate('Ymd', $timestamp);
            $isoDate = gmdate('Ymd\THis\Z', $timestamp);

            $scope = "{$date}/{$this->region}/s3/aws4_request";
            $credentialParam = "{$this->accessKeyId}/{$scope}";

            $url = "{$this->endpoint}/{$this->bucketName}/{$key}";

            $queryParams = [
                'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
                'X-Amz-Credential' => $credentialParam,
                'X-Amz-Date' => $isoDate,
                'X-Amz-Expires' => $expiresIn,
                'X-Amz-SignedHeaders' => 'host',
            ];

            ksort($queryParams);
            $canonicalQueryString = http_build_query($queryParams);

            $canonicalRequest = implode("\n", [
                'GET',
                "/{$this->bucketName}/{$key}",
                $canonicalQueryString,
                "host:{$this->accountId}.r2.cloudflarestorage.com",
                '',
                'host',
                'UNSIGNED-PAYLOAD'
            ]);

            $stringToSign = implode("\n", [
                'AWS4-HMAC-SHA256',
                $isoDate,
                $scope,
                hash('sha256', $canonicalRequest)
            ]);

            $signingKey = $this->getSigningKey($date, $this->region, 's3');
            $signature = hash_hmac('sha256', $stringToSign, $signingKey);

            return "{$url}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
        } catch (Exception $e) {
            error_log("R2 presigned URL exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * AWS Signature V4 签名
     * 修改说明: $payloadHash 直接接收Hash值，而不是原始负载
     */
    private function signRequest($method, $key, &$headers, $payloadHash, $timestamp) {
        $date = substr($timestamp, 0, 8);
        $scope = "{$date}/{$this->region}/s3/aws4_request";

        $canonicalHeaders = '';
        $signedHeaders = '';
        ksort($headers);
        foreach ($headers as $name => $value) {
            $lowerName = strtolower($name);
            $canonicalHeaders .= "{$lowerName}:{$value}\n";
            $signedHeaders .= ($signedHeaders ? ';' : '') . $lowerName;
        }

        $canonicalRequest = implode("\n", [
            $method,
            "/{$this->bucketName}/{$key}",
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash // 使用预计算的Hash
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest)
        ]);

        $signingKey = $this->getSigningKey($date, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /**
     * 生成签名密钥
     */
    private function getSigningKey($date, $region, $service) {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * 格式化请求头
     */
    private function formatHeaders($headers) {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }
        return $formatted;
    }
}
