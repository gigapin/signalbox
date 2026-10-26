<?php

declare(strict_types=1);

/** Generate temporary synthetic CA and server certificates without committing keys or invoking external tools. */

namespace Tests\Support;

use RuntimeException;

/** The local trust anchor is injected only into the adapter under test and never changes system trust. */
final class TlsCertificateFixture
{
    /** @var string Private temporary fixture directory. */
    public readonly string $directory;
    /** @var string Synthetic CA trust bundle path. */
    public readonly string $ca;
    /** @var string Combined valid server certificate and key path. */
    public readonly string $valid;
    /** @var string Combined already-expired server certificate and key path. */
    public readonly string $expired;

    /** Create an ephemeral CA and SAN-bearing valid/expired leaf certificates for loopback TLS probes. */
    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/signalbox-tls-'.bin2hex(random_bytes(8));

        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create certificate fixture storage.');
        }

        $this->ca = $this->directory.'/ca.pem';
        $this->valid = $this->directory.'/valid.pem';
        $this->expired = $this->directory.'/expired.pem';
        $config = $this->directory.'/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ca_ext]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[leaf_ext]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:tls.checker.test,IP:127.0.0.1\n");
        $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($options);
        $caRequest = openssl_csr_new(['commonName' => 'Signalbox synthetic test CA'], $caKey, $options);
        $caCertificate = openssl_csr_sign($caRequest, null, $caKey, 365, [...$options, 'x509_extensions' => 'ca_ext']);

        if ($caCertificate === false || !openssl_x509_export_to_file($caCertificate, $this->ca)) {
            throw new RuntimeException('Cannot create the synthetic CA.');
        }

        foreach ([$this->valid => 30, $this->expired => 0] as $path => $days) {
            $key = openssl_pkey_new($options);
            $request = openssl_csr_new(['commonName' => 'tls.checker.test'], $key, $options);
            $certificate = openssl_csr_sign($request, $caCertificate, $caKey, $days, [...$options, 'x509_extensions' => 'leaf_ext'], random_int(1, 1000000));

            if ($certificate === false || !openssl_x509_export($certificate, $pem) || !openssl_pkey_export($key, $private, null, $options)) {
                throw new RuntimeException('Cannot create a synthetic server certificate.');
            }

            file_put_contents($path, $pem.$private);
        }
    }

    /** Remove all owned synthetic certificates, keys and configuration after the child server has stopped. */
    public function cleanup(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }
}
