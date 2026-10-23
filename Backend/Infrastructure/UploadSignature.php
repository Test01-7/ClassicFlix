<?php
declare(strict_types=1);

namespace ClassicFlix\Infrastructure;

/**
 * Use the official SDK's SigV4 algorithm, retaining two headers that its
 * browser-friendly defaults otherwise exclude. A caller must not be able to
 * remove the create-only precondition or change the announced MIME type.
 */
final class UploadSignature extends \Aws\Signature\S3SignatureV4
{
    protected function getHeaderBlacklist()
    {
        $headers = parent::getHeaderBlacklist();
        unset($headers['if-none-match']);
        return $headers;
    }

    protected function getPresignHeaderDenyList()
    {
        $headers = parent::getPresignHeaderDenyList();
        unset($headers['content-type']);
        return $headers;
    }
}
