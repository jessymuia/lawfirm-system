<?php

namespace Tests\Unit;

use App\Catalog\SystemCatalog;
use Database\Seeders\Kenya\KenyaDemoData;
use PHPUnit\Framework\TestCase;

/** The Kenyan demo data hangs together: every reference resolves and nothing would collide when it is loaded. */
class KenyaDemoDataTest extends TestCase
{
    private const CASE_TYPES = ['civil', 'criminal', 'family', 'land', 'commercial', 'constitutional', 'labour and employment', 'administrative', 'appellate', 'election petitions'];

    public function test_people_are_unique_and_can_sign_in(): void
    {
        $emails = [];
        foreach (KenyaDemoData::firms() as $firm) {
            $this->assertNotEmpty(array_filter($firm['users'], fn ($u) => $u['role'] === 'admin'), "{$firm['slug']} needs an admin");
            foreach ($firm['users'] as $u) {
                $this->assertNotContains($u['email'], $emails, 'one email, one person');
                $emails[] = $u['email'];
                $this->assertMatchesRegularExpression('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/', $u['email']);
                $this->assertContains($u['role'], ['admin', 'advocate', 'clerk']);
                $this->assertGreaterThanOrEqual(12, strlen($u['password']), 'a real password, not a placeholder');
            }
        }
        $this->assertGreaterThanOrEqual(12, strlen(KenyaDemoData::superAdmin()['password']), 'the platform requires 12+ characters');
    }

    public function test_firms_and_their_addresses_are_unique(): void
    {
        $slugs = $domains = [];
        foreach (KenyaDemoData::firms() as $firm) {
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,62}$/', $firm['slug']);
            $this->assertNotContains($firm['slug'], $slugs);
            $slugs[] = $firm['slug'];
            foreach (array_merge([$firm['domain']], $firm['extra_domains']) as $d) {
                $this->assertNotContains($d, $domains);
                $domains[] = $d;
            }
        }
        $this->assertContains('localhost', $domains, 'one firm answers on plain localhost');
    }

    public function test_every_reference_resolves(): void
    {
        $hearingTypes = array_column(SystemCatalog::HEARING_TYPES, 'name');
        $courts = array_merge(array_column(SystemCatalog::COURTS, 'name'), array_keys(KenyaDemoData::extraCourts()));
        $courtTypes = array_keys(SystemCatalog::COURT_TYPES);
        foreach (KenyaDemoData::extraCourts() as $name => $type) {
            $this->assertContains($type, $courtTypes, "court type of {$name}");
        }

        $phones = $numbers = [];
        foreach (KenyaDemoData::firms() as $firm) {
            $emails = array_column($firm['users'], 'email');
            foreach ($firm['clients'] as $c) {
                $this->assertMatchesRegularExpression('/^(07|01)\d{8}$/', $c['phone']);
                $this->assertNotContains($c['phone'], $phones, 'client phone numbers are unique');
                $phones[] = $c['phone'];
                $this->assertContains($c['advocate'], $emails);
            }
            $keys = [];
            foreach ($firm['matters'] as $m) {
                $keys[] = $m['key'];
                $this->assertArrayHasKey($m['client'], $firm['clients']);
                $this->assertContains($m['lawyer'], $emails);
                $this->assertContains($m['court'], $courts);
                $this->assertContains($m['type'], self::CASE_TYPES);
                $this->assertNotContains($m['number'], $numbers, 'case numbers are unique');
                $numbers[] = $m['number'];
                $this->assertNotEmpty($m['hearings'], "{$m['number']} has a hearing");
                foreach ($m['hearings'] as $h) {
                    $this->assertContains($h['type'], $hearingTypes);
                    $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $h['time']);
                }
            }
            foreach ($firm['tasks'] as $t) {
                $this->assertContains($t['assignee'], $emails);
                $this->assertContains($t['priority'], ['low', 'medium', 'high']);
                $this->assertContains($t['status'], ['pending', 'in_progress', 'completed', 'overdue']);
            }
            foreach ($firm['time'] as [$key, $email]) {
                $this->assertContains($key, $keys);
                $this->assertContains($email, $emails);
            }
            foreach ($firm['invoices'] as [$key, , , $lines]) {
                $this->assertContains($key, $keys);
                $this->assertNotEmpty($lines);
            }
        }
    }
}
