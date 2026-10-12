<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class WebhookCertificate
{
    public static function create(TemporaryDirectory $directory): array
    {
        $config=$directory->path.'/openssl.cnf';
        file_put_contents($config,data: "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:receiver.example\nbasicConstraints=critical,CA:TRUE\n");
        $options=['config'=>$config,'digest_alg'=>'sha256','private_key_bits'=>2048,'x509_extensions'=>'ext'];
        $key=openssl_pkey_new($options); $csr=openssl_csr_new(['commonName'=>'receiver.example'],$key,$options);
        $cert=openssl_csr_sign($csr,ca_certificate: null,private_key: $key,days: 1,options: $options); openssl_x509_export($cert,$certPem); openssl_pkey_export($key,$keyPem,passphrase: null,options: $options);
        $certificate=$directory->path.'/certificate.pem'; file_put_contents($certificate,$certPem);
        $serverPem=$directory->path.'/receiver.pem'; file_put_contents($serverPem,$certPem.$keyPem);
        return [$certificate, $serverPem];
    }
}
