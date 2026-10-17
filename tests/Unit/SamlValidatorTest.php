<?php

namespace Tests\Unit;

use App\Sso\Saml\SamlException;
use App\Sso\Saml\SamlValidator;
use PHPUnit\Framework\TestCase;

/**
 * SEC-12: SAML responses are accepted only when signed by the firm's IdP certificate and bound to our request.
 * Fixtures (tests/Fixtures/saml-cases.json) were signed by an independent library (signxml), then forged in the ways that matter.
 * `ok_*` cases must verify; every `bad_*` case must be refused. Time is pinned to when the fixtures were made.
 */
class SamlValidatorTest extends TestCase
{
    private array $cases;

    private array $cfg;

    protected function setUp(): void
    {
        $this->cases = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/saml-cases.json'), true);
        $m = $this->cases['_meta'];
        unset($this->cases['_meta']);
        $this->cfg = [
            'idp_entity_id' => $m['idp'], 'certificates' => SamlValidator::parseCertificates($m['certificate']), 'sp_entity_id' => $m['sp'],
            'acs_url' => $m['acs'], 'request_id' => $m['req'], 'now' => $m['now'],
        ];
    }

    public function test_the_fixture_certificate_is_usable(): void
    {
        $this->assertCount(1, $this->cfg['certificates']);
    }

    public function test_valid_responses_are_accepted(): void
    {
        foreach (['ok_assertion', 'ok_response', 'ok_incl_c14n', 'ok_comment'] as $name) {
            $r = (new SamlValidator)->validate($this->cases[$name], $this->cfg);
            $this->assertSame('jane@firm.co.ke', $r['email'], $name);
            $this->assertSame('sub-jane-1', $r['name_id'], $name);
        }
    }

    public function test_every_forged_or_invalid_response_is_refused(): void
    {
        $bad = array_filter(array_keys($this->cases), fn ($n) => str_starts_with($n, 'bad_'));
        $this->assertGreaterThanOrEqual(12, count($bad));
        foreach ($bad as $name) {
            try {
                (new SamlValidator)->validate($this->cases[$name], $this->cfg);
                $this->fail("{$name} was accepted");
            } catch (SamlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_response_for_another_request_is_refused_even_when_validly_signed(): void
    {
        $this->expectException(SamlException::class);
        (new SamlValidator)->validate($this->cases['ok_assertion'], ['request_id' => '_some_other_request'] + $this->cfg);
    }

    public function test_the_signature_is_checked_against_the_configured_certificate_only(): void
    {
        // a valid response, but this firm trusts a different certificate (the message's own KeyInfo must not matter)
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'someone else'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 30), $pem);
        $other = SamlValidator::parseCertificates($pem);
        $this->assertCount(1, $other);
        $this->expectException(SamlException::class);
        (new SamlValidator)->validate($this->cases['ok_assertion'], ['certificates' => $other] + $this->cfg);
    }

    public function test_certificates_are_parsed_from_pem_or_bare_base64_and_junk_is_dropped(): void
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/saml-cases.json'), true)['_meta']['certificate'];
        $bare = trim(preg_replace('/-----[A-Z ]+-----|\s+/', '', $raw));
        $this->assertCount(1, SamlValidator::parseCertificates($raw));
        $this->assertCount(1, SamlValidator::parseCertificates($bare));
        $this->assertSame([], SamlValidator::parseCertificates('not a certificate !!'));
        $this->assertSame([], SamlValidator::parseCertificates(''));
    }
}
