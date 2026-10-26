<?php
declare(strict_types=1);

namespace ClassicFlix\Infrastructure;

use ClassicFlix\Support\Config;
use ClassicFlix\Support\HttpException;

/** All object keys come from server-owned records, never from a browser request. */
final class S3Storage
{
    private ?object $client;

    public function __construct(private readonly Config $config, ?object $client = null)
    {
        $this->client = $client;
    }

    private function client(): object
    {
        if (trim((string) $this->config->get('AWS_BUCKET', '')) === '') {
            throw new HttpException(503, 'Falta configurar el bucket de Amazon S3.');
        }
        if ($this->client !== null) {
            return $this->client;
        }
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new HttpException(503, 'Instala las dependencias de Composer para conectar Amazon S3.');
        }
        $options = [
            'version' => '2006-03-01',
            'region' => (string) $this->config->get('AWS_REGION', 'us-east-1'),
            // The browser supplies the future body: do not sign an empty-body CRC32.
            'request_checksum_calculation' => 'when_required',
            'signature_provider' => self::signatureProvider(),
            // Default provider chain supports local environment and the EC2 role.
        ];
        if ($endpoint = $this->config->get('AWS_ENDPOINT', '')) {
            $options['endpoint'] = (string) $endpoint;
            $options['use_path_style_endpoint'] = $this->config->bool('AWS_USE_PATH_STYLE', false);
        }
        return $this->client = new \Aws\S3\S3Client($options);
    }

    public static function signatureProvider(): callable
    {
        $fallback = \Aws\Signature\SignatureProvider::defaultProvider();
        return static function ($version, $service, $region) use ($fallback) {
            if ($service === 's3' && in_array($version, ['v4', 's3v4'], true)) {
                return new UploadSignature($service, $region);
            }
            return $fallback($version, $service, $region);
        };
    }

    private function object(string $key): array
    {
        return ['Bucket' => (string) $this->config->get('AWS_BUCKET', ''), 'Key' => $key];
    }

    public function presignGet(string $key, int $ttl, string $mime): string
    {
        return $this->sign('GetObject', $this->object($key) + [
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => 'inline',
        ], $ttl);
    }

    /** Same deterministic mapping as lambda/poster-thumbnail/lambda_function.py. */
    public static function thumbnailKey(string $sourceKey): ?string
    {
        if (!preg_match('~\Aclassicflix/([1-9][0-9]*)/portada/([a-f0-9]{32})\.(?:jpg|png|webp)\z~', $sourceKey, $matches)) {
            return null;
        }
        return 'miniaturas/v1/' . $matches[1] . '/' . $matches[2] . '.jpg';
    }

    public function presignPoster(string $key, int $ttl, string $mime): string
    {
        $thumbnail = self::thumbnailKey($key);
        if ($thumbnail !== null && $this->config->bool('POSTER_THUMBNAILS_ENABLED', true)) {
            try {
                $head = $this->client()->headObject($this->object($thumbnail));
                if ((int) ($head['ContentLength'] ?? 0) > 0 && ($head['ContentType'] ?? '') === 'image/jpeg') {
                    return $this->presignGet($thumbnail, $ttl, 'image/jpeg');
                }
            } catch (\Throwable) {
                // Lambda is asynchronous. Missing thumbnails or S3 errors use the original.
                // Do not expose SDK exception messages or signed requests.
            }
        }
        return $this->presignGet($key, $ttl, $mime);
    }

    public function presignPut(string $key, string $mime): string
    {
        // A reusable presigned PUT must not overwrite bytes after verification.
        return $this->sign('PutObject', $this->object($key) + ['ContentType' => $mime, 'IfNoneMatch' => '*'], 900);
    }

    private function sign(string $operation, array $arguments, int $ttl): string
    {
        $client = $this->client();
        $command = $client->getCommand($operation, $arguments);
        return (string) $client->createPresignedRequest($command, '+' . max(60, min($ttl, 14400)) . ' seconds')->getUri();
    }

    public function initiateMultipart(string $key, string $mime): string
    {
        $result = $this->client()->createMultipartUpload($this->object($key) + ['ContentType' => $mime]);
        return (string) $result['UploadId'];
    }

    public function presignPart(string $key, string $uploadId, int $part): string
    {
        return $this->sign('UploadPart', $this->object($key) + ['UploadId' => $uploadId, 'PartNumber' => $part], 900);
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload($this->object($key) + [
            'UploadId' => $uploadId,
            'IfNoneMatch' => '*',
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        try {
            $this->client()->abortMultipartUpload($this->object($key) + ['UploadId' => $uploadId]);
        } catch (\Throwable $error) {
            // The upload may already have been assembled or aborted.
            if (!method_exists($error, 'getAwsErrorCode') || $error->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $error;
            }
        }
    }

    public function inspect(string $key): array
    {
        $head = $this->client()->headObject($this->object($key));
        $sample = $this->client()->getObject($this->object($key) + ['Range' => 'bytes=0-65535']);
        return [
            'size' => (int) $head['ContentLength'],
            'mime' => strtolower(trim(explode(';', (string) ($head['ContentType'] ?? ''))[0])),
            'sample' => (string) $sample['Body'],
        ];
    }

    public function delete(string $key): void
    {
        $this->client()->deleteObject($this->object($key));
    }

    public function check(): void
    {
        $this->client()->headBucket(['Bucket' => (string) $this->config->get('AWS_BUCKET', '')]);
    }
}
