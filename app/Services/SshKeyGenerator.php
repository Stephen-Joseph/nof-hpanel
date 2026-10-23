<?php

namespace App\Services;

use RuntimeException;

class SshKeyGenerator
{
    /**
     * Generate an SSH RSA keypair suitable for Git deploy keys without any shell commands.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generate(string $comment = 'hpanel-deploy-key', int $bits = 2048): array
    {
        $tmpConfig = tempnam(sys_get_temp_dir(), 'openssl_cnf_');
        if ($tmpConfig === false) {
            throw new RuntimeException('Failed to allocate temporary configuration for OpenSSL key generation.');
        }

        try {
            file_put_contents($tmpConfig, '');

            $res = openssl_pkey_new([
                'config' => $tmpConfig,
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            if ($res === false) {
                throw new RuntimeException('OpenSSL key generation failed: '.(openssl_error_string() ?: 'unknown error'));
            }

            $privateKey = '';
            if (! openssl_pkey_export($res, $privateKey, null, ['config' => $tmpConfig])) {
                throw new RuntimeException('Failed to export OpenSSL private key.');
            }

            /** @var array{rsa?: array{e: string, n: string}}|false $details */
            $details = openssl_pkey_get_details($res);
            if ($details === false || ! isset($details['rsa'])) {
                throw new RuntimeException('Failed to extract RSA details for OpenSSH public key conversion.');
            }

            $rsa = $details['rsa'];
            $buffer = pack('N', 7).'ssh-rsa'.pack('Na*', strlen($rsa['e']), $rsa['e']).pack('Na*', strlen($rsa['n']), $rsa['n']);
            $publicKey = 'ssh-rsa '.base64_encode($buffer).' '.$comment;

            return [
                'public_key' => trim($publicKey),
                'private_key' => trim($privateKey),
            ];
        } finally {
            if (file_exists($tmpConfig)) {
                @unlink($tmpConfig);
            }
        }
    }
}
