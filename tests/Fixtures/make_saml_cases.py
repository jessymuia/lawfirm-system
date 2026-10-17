"""Builds tests/Fixtures/saml-cases.json for SamlValidatorTest: responses signed with signxml, then forged in the ways that matter.

Usage: pip install signxml && python tests/Fixtures/make_saml_cases.py tests/Fixtures/saml-cases.json
"""
import copy
import datetime as dt
import json
import sys

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from lxml import etree
from signxml import XMLSigner, SignatureMethod, DigestAlgorithm, CanonicalizationMethod, methods

P = 'urn:oasis:names:tc:SAML:2.0:protocol'
A = 'urn:oasis:names:tc:SAML:2.0:assertion'
DS = 'http://www.w3.org/2000/09/xmldsig#'

IDP = 'https://idp.example.test/saml'
SP = 'https://firm.example.test/api/v1/sso/saml/metadata'
ACS = 'https://firm.example.test/api/v1/sso/saml/acs'
REQ = '_req_8f2c1e5a9b7d4c3e'
NOW = 1792238400  # 2026-10-17T12:00:00Z
EMAIL = 'jane@firm.co.ke'
SUB = 'sub-jane-1'


def iso(offset):
    return dt.datetime.fromtimestamp(NOW + offset, dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def keypair(cn):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, cn)])
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(dt.datetime(2026, 1, 1, tzinfo=dt.timezone.utc))
            .not_valid_after(dt.datetime(2036, 1, 1, tzinfo=dt.timezone.utc))
            .sign(key, hashes.SHA256()))
    return (key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()),
            cert.public_bytes(serialization.Encoding.PEM))


KEY, CERT = keypair('idp.example.test')
OTHER_KEY, OTHER_CERT = keypair('attacker.example.test')


def response(*, assertion_id='_a1', response_id='_r1', name_id=SUB, email=EMAIL, issuer=IDP, audience=SP,
             recipient=ACS, in_response_to=REQ, destination=ACS, status='urn:oasis:names:tc:SAML:2.0:status:Success',
             not_before=-60, not_on_or_after=300, comment_in_name_id=False):
    """One line, no insignificant whitespace (the validator loads with LIBXML_NOBLANKS)."""
    nid = (name_id[:4] + '<!---->' + name_id[4:]) if comment_in_name_id else name_id
    xml = (
        f'<samlp:Response xmlns:samlp="{P}" xmlns:saml="{A}" ID="{response_id}" Version="2.0" IssueInstant="{iso(0)}" '
        f'Destination="{destination}" InResponseTo="{in_response_to}">'
        f'<saml:Issuer>{issuer}</saml:Issuer>'
        f'<samlp:Status><samlp:StatusCode Value="{status}"/></samlp:Status>'
        f'<saml:Assertion ID="{assertion_id}" Version="2.0" IssueInstant="{iso(0)}">'
        f'<saml:Issuer>{issuer}</saml:Issuer>'
        f'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:persistent">{nid}</saml:NameID>'
        f'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
        f'<saml:SubjectConfirmationData NotOnOrAfter="{iso(300)}" Recipient="{recipient}" InResponseTo="{REQ}"/>'
        f'</saml:SubjectConfirmation></saml:Subject>'
        f'<saml:Conditions NotBefore="{iso(not_before)}" NotOnOrAfter="{iso(not_on_or_after)}">'
        f'<saml:AudienceRestriction><saml:Audience>{audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
        f'<saml:AuthnStatement AuthnInstant="{iso(0)}" SessionIndex="_s1" SessionNotOnOrAfter="{iso(28800)}">'
        f'<saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>'
        f'</saml:AuthnStatement>'
        f'<saml:AttributeStatement><saml:Attribute Name="email"><saml:AttributeValue>{email}</saml:AttributeValue></saml:Attribute></saml:AttributeStatement>'
        f'</saml:Assertion></samlp:Response>'
    )
    return etree.fromstring(xml.encode())


def sign(root, target='assertion', key=KEY, cert=CERT, c14n=CanonicalizationMethod.EXCLUSIVE_XML_CANONICALIZATION_1_0,
         alg=SignatureMethod.RSA_SHA256, digest=DigestAlgorithm.SHA256):
    """Enveloped signature placed right after the Issuer of the signed element."""
    el = root if target == 'response' else root.find(f'{{{A}}}Assertion')
    placeholder = etree.Element(f'{{{DS}}}Signature', Id='placeholder', nsmap={'ds': DS})
    el.insert(1, placeholder)
    signer = XMLSigner(method=methods.enveloped, signature_algorithm=alg, digest_algorithm=digest, c14n_algorithm=c14n)
    signer.namespaces = {'ds': DS}
    signed = signer.sign(root, key=key, cert=cert, reference_uri='#' + el.get('ID'), always_add_key_value=False)
    return signed


def s(root):
    return etree.tostring(root, encoding='unicode')


def A_(root):
    return root.find(f'{{{A}}}Assertion')


cases = {}
cases['ok_assertion'] = s(sign(response()))
cases['ok_response'] = s(sign(response(), target='response'))
cases['ok_incl_c14n'] = s(sign(response(), c14n=CanonicalizationMethod.CANONICAL_XML_1_0))
cases['ok_comment'] = s(sign(response(comment_in_name_id=True)))

# altered after signing
r = sign(response())
A_(r).find(f'{{{A}}}Subject/{{{A}}}NameID').text = 'sub-admin-1'
cases['bad_tampered_name_id'] = s(r)

r = sign(response())
A_(r).find(f'.//{{{A}}}AttributeValue').text = 'boss@firm.co.ke'
cases['bad_tampered_email'] = s(r)

r = sign(response(), target='response')
A_(r).find(f'.//{{{A}}}AttributeValue').text = 'boss@firm.co.ke'
cases['bad_tampered_inside_signed_response'] = s(r)

cases['bad_unsigned'] = s(response())

# signature wrapping: an evil assertion next to (or around) the genuine signed one
r = sign(response())
evil = copy.deepcopy(A_(r))
evil.remove(evil.find(f'{{{DS}}}Signature'))
evil.find(f'.//{{{A}}}AttributeValue').text = 'boss@firm.co.ke'
r.insert(2, evil)
cases['bad_wrapping_two_assertions'] = s(r)

r = sign(response())
genuine = A_(r)
evil = copy.deepcopy(genuine)
evil.remove(evil.find(f'{{{DS}}}Signature'))
evil.find(f'.//{{{A}}}AttributeValue').text = 'boss@firm.co.ke'
evil.set('ID', '_evil')
r.replace(genuine, evil)
ext = etree.SubElement(r, f'{{{P}}}Extensions')
ext.append(genuine)
cases['bad_wrapping_moved_assertion'] = s(r)

r = sign(response(), target='response')
evil = response(assertion_id='_a1', email='boss@firm.co.ke')
r.find(f'{{{A}}}Assertion').addnext(copy.deepcopy(A_(evil)))
cases['bad_wrapping_duplicate_id'] = s(r)

# signed by someone else, or with SHA-1
cases['bad_wrong_key'] = s(sign(response(), key=OTHER_KEY, cert=OTHER_CERT))
try:
    cases['bad_sha1'] = s(sign(response(), alg=SignatureMethod.RSA_SHA1, digest=DigestAlgorithm.SHA1))
except Exception:
    r = sign(response())
    sm = r.find(f'.//{{{DS}}}SignatureMethod')
    sm.set('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1')
    cases['bad_sha1'] = s(r)

# validly signed, but not for us / not now
cases['bad_expired'] = s(sign(response(not_before=-7200, not_on_or_after=-3600)))
cases['bad_not_yet_valid'] = s(sign(response(not_before=3600, not_on_or_after=7200)))
cases['bad_audience'] = s(sign(response(audience='https://other-sp.example.test')))
cases['bad_issuer'] = s(sign(response(issuer='https://evil-idp.example.test')))
cases['bad_recipient'] = s(sign(response(recipient='https://evil.example.test/acs')))
cases['bad_in_response_to'] = s(sign(response(in_response_to='_req_someone_else')))
cases['bad_destination'] = s(sign(response(destination='https://evil.example.test/acs')))
cases['bad_status'] = s(sign(response(status='urn:oasis:names:tc:SAML:2.0:status:Requester')))

# the signature points elsewhere
r = sign(response())
r.find(f'.//{{{DS}}}Reference').set('URI', '#_r1')
cases['bad_reference_elsewhere'] = s(r)

cases['bad_dtd'] = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x "jane@firm.co.ke">]>' + cases['ok_assertion']
cases['bad_encrypted'] = cases['ok_assertion'].replace('</samlp:Response>', f'<saml:EncryptedAssertion><xenc:EncryptedData xmlns:xenc="http://www.w3.org/2001/04/xmlenc#"/></saml:EncryptedAssertion></samlp:Response>')
cases['bad_not_xml'] = cases['ok_assertion'][:-20]

out = {'_meta': {'idp': IDP, 'sp': SP, 'acs': ACS, 'req': REQ, 'now': NOW, 'certificate': CERT.decode(),
                 'note': 'Signed with signxml (lxml), then forged. Regenerate both ok_ and bad_ cases together.'}}
out.update(cases)
json.dump(out, open(sys.argv[1], 'w'), indent=2)
print(len(cases), 'cases')
