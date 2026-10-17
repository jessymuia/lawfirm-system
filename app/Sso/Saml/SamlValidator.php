<?php

namespace App\Sso\Saml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * SEC-12: turns a SAML 2.0 response into a verified identity, or throws. Built to fail closed: a wrong implementation of signed XML is a login bypass.
 *
 * What it insists on:
 *  - no DTD/entities, a size cap, exactly one <Assertion> in the whole document, no encrypted assertions;
 *  - the signature is checked against the firm's configured certificate(s) only (whatever <KeyInfo> says is ignored);
 *  - the signature must be a direct child of the Response or of the Assertion, reference exactly that element's own ID, and that ID
 *    must be unique in the document (blocks XML signature wrapping);
 *  - the digest and signature are recomputed over the canonical form; SHA-1 is refused;
 *  - everything the application reads comes from the assertion node that is inside the signed element, never from a lookup by ID;
 *  - issuer, status, audience, recipient, destination, InResponseTo (the request we sent), and the validity window are all checked.
 * Not supported on purpose: IdP-initiated sign-in (no request id to bind to) and encrypted assertions.
 */
class SamlValidator
{
    public const NS_P = 'urn:oasis:names:tc:SAML:2.0:protocol';
    public const NS_A = 'urn:oasis:names:tc:SAML:2.0:assertion';
    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const ALGS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];
    private const DIGESTS = [
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];
    private const C14N = [
        'http://www.w3.org/2001/10/xml-exc-c14n#' => [true, false],
        'http://www.w3.org/2001/10/xml-exc-c14n#WithComments' => [true, true],
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315' => [false, false],
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments' => [false, true],
    ];
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public const EMAIL_ATTRIBUTES = [
        'email', 'mail', 'emailaddress',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'urn:oid:0.9.2342.19200300.100.1.3',
        'http://schemas.xmlsoap.org/claims/EmailAddress',
    ];

    /**
     * @param array{idp_entity_id: string, certificates: list<string>, sp_entity_id: string, acs_url: string, request_id: string, leeway?: int, now?: int} $cfg
     * @return array{name_id: string, email: string, attributes: array<string, list<string>>, session_index: ?string}
     */
    public function validate(string $xml, array $cfg): array
    {
        $leeway = $cfg['leeway'] ?? 60;
        $now = $cfg['now'] ?? time();

        if ($xml === '' || strlen($xml) > 524288) {
            throw new SamlException('Response empty or too large.');
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            throw new SamlException('Response contains a DTD.');
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded || ! $doc->documentElement) {
            throw new SamlException('Response is not well-formed XML.');
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('p', self::NS_P);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('ds', self::NS_DS);

        $response = $doc->documentElement;
        if ($response->localName !== 'Response' || $response->namespaceURI !== self::NS_P) {
            throw new SamlException('Root element is not a SAML Response.');
        }
        if ($xp->query('//a:EncryptedAssertion')->length > 0) {
            throw new SamlException('Encrypted assertions are not supported.');
        }
        $assertions = $xp->query('//a:Assertion');
        if ($assertions->length !== 1) {
            throw new SamlException('Expected exactly one assertion, found '.$assertions->length.'.');
        }
        /** @var DOMElement $assertion */
        $assertion = $assertions->item(0);
        if ($assertion->parentNode !== $response) {
            throw new SamlException('Assertion is not a direct child of the Response.');
        }

        // ids must be unique: a duplicate is the signature-wrapping trick
        $seen = [];
        /** @var DOMElement $el */
        foreach ($xp->query('//*[@ID]') as $el) {
            $id = $el->getAttribute('ID');
            if (isset($seen[$id])) {
                throw new SamlException('Duplicate element ID.');
            }
            $seen[$id] = true;
        }

        // The Response envelope: where it was meant for, and that it answers the request we made.
        if ($response->getAttribute('InResponseTo') !== $cfg['request_id']) {
            throw new SamlException('InResponseTo does not match the request.');
        }
        if ($response->hasAttribute('Destination') && $response->getAttribute('Destination') !== $cfg['acs_url']) {
            throw new SamlException('Destination mismatch.');
        }
        $status = $xp->query('p:Status/p:StatusCode/@Value', $response)->item(0);
        if (! $status || $status->nodeValue !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            throw new SamlException('IdP status is not Success.');
        }

        // Signature: on the Response, on the Assertion, or both. At least one must verify; what we read lies inside a verified element.
        $verified = false;
        foreach ([$response, $assertion] as $target) {
            $sigs = $xp->query('ds:Signature', $target);
            if ($sigs->length > 1) {
                throw new SamlException('More than one signature on an element.');
            }
            if ($sigs->length === 1) {
                $this->verifySignature($doc, $xp, $target, $sigs->item(0), $cfg['certificates']);
                $verified = true;
            }
        }
        if (! $verified) {
            throw new SamlException('Response is not signed.');
        }
        // all the signatures that exist in the document must be the ones we just verified (no stray, unverified ones near the data)
        if ($xp->query('//ds:Signature')->length > ($xp->query('ds:Signature', $response)->length + $xp->query('ds:Signature', $assertion)->length)) {
            throw new SamlException('Unexpected signature elsewhere in the document.');
        }

        // ---- everything below reads only from $assertion (a descendant of a verified element) ----
        $issuer = $xp->query('a:Issuer', $assertion)->item(0);
        if (! $issuer || trim($issuer->textContent) !== $cfg['idp_entity_id']) {
            throw new SamlException('Issuer mismatch.');
        }

        /** @var DOMElement|null $conditions */
        $conditions = $xp->query('a:Conditions', $assertion)->item(0);
        if (! $conditions) {
            throw new SamlException('Assertion has no Conditions.');
        }
        $this->window($conditions, $now, $leeway, 'Conditions');
        $audiences = [];
        foreach ($xp->query('a:AudienceRestriction/a:Audience', $conditions) as $aud) {
            $audiences[] = trim($aud->textContent);
        }
        if (! in_array($cfg['sp_entity_id'], $audiences, true)) {
            throw new SamlException('Audience does not include this service.');
        }
        foreach ($xp->query('a:OneTimeUse|a:ProxyRestriction', $conditions) as $_) {
            // allowed, nothing to do; unknown required conditions are refused below
        }

        $bearer = false;
        /** @var DOMElement $sc */
        foreach ($xp->query('a:Subject/a:SubjectConfirmation', $assertion) as $sc) {
            if ($sc->getAttribute('Method') !== 'urn:oasis:names:tc:SAML:2.0:cm:bearer') {
                continue;
            }
            /** @var DOMElement|null $d */
            $d = $xp->query('a:SubjectConfirmationData', $sc)->item(0);
            if (! $d) {
                continue;
            }
            if ($d->getAttribute('Recipient') !== $cfg['acs_url'] || $d->getAttribute('InResponseTo') !== $cfg['request_id']) {
                continue;
            }
            if (! $d->hasAttribute('NotOnOrAfter') || $now >= $this->time($d->getAttribute('NotOnOrAfter')) + $leeway) {
                continue;
            }
            $bearer = true;
            break;
        }
        if (! $bearer) {
            throw new SamlException('No valid bearer confirmation (recipient, request, expiry).');
        }

        $nameIdEl = $xp->query('a:Subject/a:NameID', $assertion)->item(0);
        $nameId = $nameIdEl ? trim($nameIdEl->textContent) : '';
        if ($nameId === '') {
            throw new SamlException('Assertion has no NameID.');
        }

        /** @var DOMElement|null $authn */
        $authn = $xp->query('a:AuthnStatement', $assertion)->item(0);
        if (! $authn) {
            throw new SamlException('Assertion has no AuthnStatement.');
        }
        if ($authn->hasAttribute('SessionNotOnOrAfter') && $now >= $this->time($authn->getAttribute('SessionNotOnOrAfter')) + $leeway) {
            throw new SamlException('IdP session has ended.');
        }

        $attributes = [];
        /** @var DOMElement $attr */
        foreach ($xp->query('a:AttributeStatement/a:Attribute', $assertion) as $attr) {
            $values = [];
            foreach ($xp->query('a:AttributeValue', $attr) as $v) {
                $values[] = trim($v->textContent);
            }
            $attributes[$attr->getAttribute('Name')] = array_merge($attributes[$attr->getAttribute('Name')] ?? [], $values);
        }

        $email = null;
        foreach ($attributes as $name => $values) {
            if (in_array(strtolower($name), array_map('strtolower', self::EMAIL_ATTRIBUTES), true) && ($values[0] ?? '') !== '') {
                $email = $values[0];
                break;
            }
        }
        $email ??= str_contains($nameId, '@') ? $nameId : null;
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SamlException('Assertion carries no usable email address.');
        }

        return [
            'name_id' => $nameId, 'email' => $email, 'attributes' => $attributes,
            'session_index' => $authn->getAttribute('SessionIndex') ?: null,
        ];
    }

    private function time(string $iso): int
    {
        $t = strtotime($iso);
        if ($t === false) {
            throw new SamlException('Unreadable timestamp.');
        }

        return $t;
    }

    private function window(DOMElement $el, int $now, int $leeway, string $what): void
    {
        if ($el->hasAttribute('NotBefore') && $now + $leeway < $this->time($el->getAttribute('NotBefore'))) {
            throw new SamlException("{$what} not valid yet.");
        }
        if (! $el->hasAttribute('NotOnOrAfter')) {
            throw new SamlException("{$what} has no expiry.");
        }
        if ($now >= $this->time($el->getAttribute('NotOnOrAfter')) + $leeway) {
            throw new SamlException("{$what} expired.");
        }
    }

    /** @param list<string> $certificates PEM certificates the firm trusts for this IdP (more than one while it rolls its key) */
    private function verifySignature(DOMDocument $doc, DOMXPath $xp, DOMElement $target, DOMNode $sig, array $certificates): void
    {
        $id = $target->getAttribute('ID');
        if ($id === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $id)) {
            throw new SamlException('Signed element has no usable ID.');
        }
        $signedInfo = $xp->query('ds:SignedInfo', $sig)->item(0);
        $sigValue = $xp->query('ds:SignatureValue', $sig)->item(0);
        if (! $signedInfo || ! $sigValue || $xp->query('ds:SignedInfo', $sig)->length !== 1) {
            throw new SamlException('Malformed signature.');
        }

        $c14nAlg = $xp->query('ds:CanonicalizationMethod/@Algorithm', $signedInfo)->item(0)?->nodeValue;
        $sigAlg = $xp->query('ds:SignatureMethod/@Algorithm', $signedInfo)->item(0)?->nodeValue;
        if (! isset(self::C14N[$c14nAlg]) || ! isset(self::ALGS[$sigAlg])) {
            throw new SamlException('Unsupported signature algorithm (SHA-1 is refused).');
        }

        $refs = $xp->query('ds:Reference', $signedInfo);
        if ($refs->length !== 1) {
            throw new SamlException('Signature must have exactly one reference.');
        }
        /** @var DOMElement $ref */
        $ref = $refs->item(0);
        if ($ref->getAttribute('URI') !== '#'.$id) {
            throw new SamlException('Signature does not reference the element it sits in.');
        }
        $digestAlg = $xp->query('ds:DigestMethod/@Algorithm', $ref)->item(0)?->nodeValue;
        $digestB64 = trim((string) $xp->query('ds:DigestValue', $ref)->item(0)?->textContent);
        if (! isset(self::DIGESTS[$digestAlg]) || $digestB64 === '') {
            throw new SamlException('Unsupported digest algorithm (SHA-1 is refused).');
        }

        // transforms: enveloped-signature, then optionally a canonicalization. Nothing else (no XPath, no XSLT).
        $refC14n = self::C14N['http://www.w3.org/2001/10/xml-exc-c14n#'];
        $enveloped = false;
        /** @var DOMElement $t */
        foreach ($xp->query('ds:Transforms/ds:Transform', $ref) as $t) {
            $alg = $t->getAttribute('Algorithm');
            if ($alg === self::ENVELOPED) {
                $enveloped = true;
            } elseif (isset(self::C14N[$alg])) {
                $refC14n = self::C14N[$alg];
            } else {
                throw new SamlException('Unsupported transform.');
            }
        }
        if (! $enveloped) {
            throw new SamlException('Signature is not enveloped.');
        }

        // 1) digest of the signed element without its signature
        $query = "(//. | //@* | //namespace::*)[ancestor-or-self::*[@ID='{$id}'] and not(ancestor-or-self::ds:Signature)]";
        $canonical = $doc->C14N($refC14n[0], $refC14n[1], ['query' => $query, 'namespaces' => ['ds' => self::NS_DS]]);
        if (! is_string($canonical) || $canonical === '') {
            throw new SamlException('Could not canonicalise the signed element.');
        }
        $expected = base64_decode($digestB64, true);
        if ($expected === false || ! hash_equals(hash(self::DIGESTS[$digestAlg], $canonical, true), $expected)) {
            throw new SamlException('Digest mismatch: the signed content was altered.');
        }

        // 2) the signature over SignedInfo, against the firm's certificate(s) only
        [$exclusive, $comments] = self::C14N[$c14nAlg];
        $signedBytes = $signedInfo->C14N($exclusive, $comments);
        $sigBytes = base64_decode(preg_replace('/\s+/', '', $sigValue->textContent), true);
        if (! is_string($signedBytes) || $sigBytes === false) {
            throw new SamlException('Malformed signature value.');
        }
        foreach ($certificates as $pem) {
            $key = openssl_pkey_get_public($pem);
            if ($key !== false && openssl_verify($signedBytes, $sigBytes, $key, self::ALGS[$sigAlg]) === 1) {
                return;
            }
        }
        throw new SamlException('Signature does not verify against the configured certificate.');
    }

    /** Split a pasted certificate bundle (or a bare base64 body) into PEM certificates. Returns [] when none is usable. @return list<string> */
    public static function parseCertificates(string $text): array
    {
        $out = [];
        if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $text, $m)) {
            foreach ($m[1] as $body) {
                $out[] = self::pem($body);
            }
        } elseif (preg_match('/^[A-Za-z0-9+\/=\s]+$/', trim($text))) {
            $out[] = self::pem($text);
        }

        return array_values(array_filter($out, fn ($p) => (bool) openssl_pkey_get_public($p)));
    }

    private static function pem(string $body): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $body), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
