<?php

namespace Selective\XmlDSig;

use DOMDocument;
use DOMXPath;
use Selective\XmlDSig\Exception\XmlSignerException;

/**
 * Sign XML Documents with Digital Signatures (XMLDSIG).
 */
final class XmlSigner
{
    //
    // Signature Algorithm Identifiers, RSA (PKCS#1 v1.5)
    // https://www.w3.org/TR/xmldsig-core/#sec-PKCS1
    //
    private const SIGNATURE_SHA1_URL = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private const SIGNATURE_SHA224_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha224';
    private const SIGNATURE_SHA256_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const SIGNATURE_SHA384_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384';
    private const SIGNATURE_SHA512_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512';

    //
    // Digest Algorithm Identifiers
    // https://www.w3.org/TR/xmldsig-core/#sec-AlgID
    //
    private const DIGEST_SHA1_URL = 'http://www.w3.org/2000/09/xmldsig#sha1';
    private const DIGEST_SHA224_URL = 'http://www.w3.org/2001/04/xmldsig-more#sha224';
    private const DIGEST_SHA256_URL = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const DIGEST_SHA384_URL = 'http://www.w3.org/2001/04/xmldsig-more#sha384';
    private const DIGEST_SHA512_URL = 'http://www.w3.org/2001/04/xmlenc#sha512';

    /**
     * @var int
     */
    private $sslAlgorithm;

    /**
     * @var string
     */
    private $algorithmName;

    /**
     * @var string
     */
    private $signatureAlgorithmUrl;

    /**
     * @var string
     */
    private $digestAlgorithmUrl;

    /**
     * @var resource|false
     */
    private $privateKeyId;

    /**
     * @var string
     */
    private $referenceUri = '';

    /**
     * @var string
     */
    private $modulus;

    /**
     * @var string
     */
    private $publicExponent;

    /**
     * Read and load the pfx file.
     *
     * @param string $filename PFX filename
     * @param string $password PFX password
     *
     * @throws XmlSignerException
     *
     * @return bool Success
     */
    public function loadPfxFile(string $filename, string $password): bool
    {
        if (!file_exists($filename)) {
            throw new XmlSignerException(sprintf('File not found: %s', $filename));
        }

        $certStore = file_get_contents($filename);

        if (!$certStore) {
            throw new XmlSignerException(sprintf('File could not be read: %s', $filename));
        }

        $status = openssl_pkcs12_read($certStore, $certInfo, $password);

        if (!$status) {
            throw new XmlSignerException('Invalid PFX password');
        }

        // Read the private key
        $this->privateKeyId = openssl_pkey_get_private((string)$certInfo['pkey']);

        if (!$this->privateKeyId) {
            throw new XmlSignerException('Invalid private key');
        }

        $this->loadPrivateKeyDetails();

        return true;
    }

    /**
     * Read and load a private key file.
     *
     * @param string $filename The PEM filename
     * @param string $password The PEM password
     *
     * @throws XmlSignerException
     *
     * @return bool Success
     */
    public function loadPrivateKeyFile(string $filename, string $password): bool
    {
        if (!file_exists($filename)) {
            throw new XmlSignerException(sprintf('File not found: %s', $filename));
        }

        $certStore = file_get_contents($filename);

        if (!$certStore) {
            throw new XmlSignerException(sprintf('File could not be read: %s', $filename));
        }

        // Read the private key
        $this->privateKeyId = openssl_pkey_get_private($certStore, $password);

        if (!$this->privateKeyId) {
            throw new XmlSignerException('Invalid password or private key');
        }

        $this->loadPrivateKeyDetails();

        return true;
    }

    /**
     * Load private key details.
     *
     * @return void
     */
    private function loadPrivateKeyDetails()
    {
        $details = openssl_pkey_get_details($this->privateKeyId);

        $key = $this->getPrivateKeyDetailKey($details['type']);
        $this->modulus = base64_encode($details[$key]['n']);
        $this->publicExponent = base64_encode($details[$key]['e']);
    }

    /**
     * Get private key details key type.
     *
     * @param int $type The type
     *
     * @return string The array key
     */
    private function getPrivateKeyDetailKey(int $type): string
    {
        $key = '';
        $key = $type === OPENSSL_KEYTYPE_RSA ? 'rsa' : $key;
        $key = $type === OPENSSL_KEYTYPE_DSA ? 'dsa' : $key;
        $key = $type === OPENSSL_KEYTYPE_DH ? 'dh' : $key;
        $key = $type === OPENSSL_KEYTYPE_EC ? 'ec' : $key;

        return $key;
    }

    /**
     * Sign an XML file and save the signature in a new file.
     * This method does not save the public key within the XML file.
     *
     * @param string $filename Input file
     * @param string $outputFilename Output file
     * @param string $algorithm For example: sha1, sha224, sha256, sha384, sha512
     *
     * @throws XmlSignerException
     *
     * @return bool Success
     */
    public function signXmlFile(string $filename, string $outputFilename, string $algorithm): bool
    {
        if (!file_exists($filename)) {
            throw new XmlSignerException(sprintf('File not found: %s', $filename));
        }

        if (!$this->privateKeyId) {
            throw new XmlSignerException('No private key provided');
        }

        $this->setAlgorithm($algorithm);

        // Read the xml file content
        $xml = new DOMDocument();

        // Whitespaces must be preserved
        $xml->preserveWhiteSpace = true;

        $xml->formatOutput = false;

        $xml->load($filename);

        // Canonicalize the content, exclusive and without comments
        $canonicalData = $xml->documentElement->C14N(true, false);

        // Calculate and encode digest value
        $digestValue = base64_encode(openssl_digest($canonicalData, $this->algorithmName, true));

        $this->appendSignature($xml, $digestValue);

        file_put_contents($outputFilename, $xml->saveXML());

        return true;
    }

    /**
     * Set reference URI.
     *
     * @param string $referenceUri The reference URI
     *
     * @return void
     */
    public function setReferenceUri(string $referenceUri)
    {
        $this->referenceUri = $referenceUri;
    }

    /**
     * Create the XML representation of the signature.
     *
     * @param DOMDocument $xml The xml document
     * @param string $digestValue The digest value
     *
     * @return void The DOM document
     */
    private function appendSignature(DOMDocument $xml, string $digestValue)
    {
        $signatureElement = $xml->createElement('Signature');
        $signatureElement->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');

        // Append the element to the XML document.
        // We insert the new element as root (child of the document)
        $xml->documentElement->appendChild($signatureElement);

        $signedInfoElement = $xml->createElement('SignedInfo');
        $signatureElement->appendChild($signedInfoElement);

        $canonicalizationMethodElement = $xml->createElement('CanonicalizationMethod');
        $canonicalizationMethodElement->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfoElement->appendChild($canonicalizationMethodElement);

        $signatureMethodElement = $xml->createElement('SignatureMethod');
        $signatureMethodElement->setAttribute('Algorithm', $this->signatureAlgorithmUrl);
        $signedInfoElement->appendChild($signatureMethodElement);

        $referenceElement = $xml->createElement('Reference');
        $referenceElement->setAttribute('URI', $this->referenceUri);
        $signedInfoElement->appendChild($referenceElement);

        $transformsElement = $xml->createElement('Transforms');
        $referenceElement->appendChild($transformsElement);

        // Enveloped: the <Signature> node is inside the XML we want to sign
        $transformElement = $xml->createElement('Transform');
        $transformElement->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transformsElement->appendChild($transformElement);

        $digestMethodElement = $xml->createElement('DigestMethod');
        $digestMethodElement->setAttribute('Algorithm', $this->digestAlgorithmUrl);
        $referenceElement->appendChild($digestMethodElement);

        $digestValueElement = $xml->createElement('DigestValue', $digestValue);
        $referenceElement->appendChild($digestValueElement);

        $signatureValueElement = $xml->createElement('SignatureValue', '');
        $signatureElement->appendChild($signatureValueElement);

        $keyInfoElement = $xml->createElement('KeyInfo');
        $signatureElement->appendChild($keyInfoElement);

        $keyValueElement = $xml->createElement('KeyValue');
        $keyInfoElement->appendChild($keyValueElement);

        $rsaKeyValueElement = $xml->createElement('RSAKeyValue');
        $keyValueElement->appendChild($rsaKeyValueElement);

        $modulusElement = $xml->createElement('Modulus', $this->modulus);
        $rsaKeyValueElement->appendChild($modulusElement);

        $exponentElement = $xml->createElement('Exponent', $this->publicExponent);
        $rsaKeyValueElement->appendChild($exponentElement);

        // http://www.soapclient.com/XMLCanon.html
        $c14nSignedInfo = $signedInfoElement->C14N(true, false);

        // Calculate and encode digest value
        $status = openssl_sign($c14nSignedInfo, $signatureValue, $this->privateKeyId, $this->sslAlgorithm);

        if (!$status) {
            throw new XmlSignerException('Computing of the signature failed');
        }

        $xpath = new DOMXpath($xml);
        $signatureValueElement = $xpath->query('//SignatureValue', $signatureElement)->item(0);
        $signatureValueElement->nodeValue = base64_encode($signatureValue);
    }

    /**
     * Set signature and digest algorithm.
     *
     * @param string $algorithm For example: sha1, sha224, sha256, sha384, sha512
     */
    private function setAlgorithm(string $algorithm): void
    {
        switch ($algorithm) {
            case 'sha1':
                $this->signatureAlgorithmUrl = self::SIGNATURE_SHA1_URL;
                $this->digestAlgorithmUrl = self::DIGEST_SHA1_URL;
                $this->sslAlgorithm = OPENSSL_ALGO_SHA1;
                break;
            case 'sha224':
                $this->signatureAlgorithmUrl = self::SIGNATURE_SHA224_URL;
                $this->digestAlgorithmUrl = self::DIGEST_SHA224_URL;
                $this->sslAlgorithm = OPENSSL_ALGO_SHA224;
                break;
            case 'sha256':
                $this->signatureAlgorithmUrl = self::SIGNATURE_SHA256_URL;
                $this->digestAlgorithmUrl = self::DIGEST_SHA256_URL;
                $this->sslAlgorithm = OPENSSL_ALGO_SHA256;
                break;
            case 'sha384':
                $this->signatureAlgorithmUrl = self::SIGNATURE_SHA384_URL;
                $this->digestAlgorithmUrl = self::DIGEST_SHA384_URL;
                $this->sslAlgorithm = OPENSSL_ALGO_SHA384;
                break;
            case 'sha512':
                $this->signatureAlgorithmUrl = self::SIGNATURE_SHA512_URL;
                $this->digestAlgorithmUrl = self::DIGEST_SHA512_URL;
                $this->sslAlgorithm = OPENSSL_ALGO_SHA512;
                break;
            default:
                throw new XmlSignerException("Cannot validate digest: Unsupported algorithm <$algorithm>");
        }

        $this->algorithmName = $algorithm;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        // Free the key from memory
        if ($this->privateKeyId) {
            openssl_free_key($this->privateKeyId);
        }
    }
}
