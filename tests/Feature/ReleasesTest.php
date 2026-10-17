<?php

namespace Tests\Feature;

use App\Releases\Changelog;
use App\Releases\ConventionalCommits;
use App\Releases\ReleaseNotes;
use App\Releases\Semver;
use App\Support\ReleaseLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** PLAT-10: semver, changelog parsing, release notes between versions, the release gate, drafting from commits, the releases API. */
class ReleasesTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/plat10-'.uniqid();
        mkdir($this->dir.'/upgrade', 0777, true);
    }

    private const GOOD_GUIDE = "# Upgrading\n\n## Before you upgrade\nx\n\n## Required actions\n1. y\n\n## Rollback\nz\n";

    private function notes(string $changelog, string $version, array $guides = []): ReleaseNotes
    {
        file_put_contents($this->dir.'/CHANGELOG.md', $changelog);
        file_put_contents($this->dir.'/VERSION', $version."\n");
        foreach ($guides as $name => $body) {
            file_put_contents($this->dir."/upgrade/{$name}.md", $body === true ? self::GOOD_GUIDE : $body);
        }

        return new ReleaseNotes($this->dir.'/CHANGELOG.md', $this->dir.'/upgrade', $this->dir.'/VERSION');
    }

    private const LOG = <<<'MD'
# Changelog

## [Unreleased]

### Added
- Next thing

## [1.2.0] - 2026-09-01
### Added
- Big feature
  continued on a second line
### Fixed
- A bug

## [1.1.1] - 2026-08-01
### Fixed
- Tiny fix

## [1.1.0] - 2026-07-01
### Added
- Something
### Breaking
- Old endpoint removed

## [1.0.0] - 2026-06-01
First stable release.
- Highlight one
MD;

    // ---- semver ----------------------------------------------------------------------------------------------------

    public function test_semver_validity(): void
    {
        foreach (['1.0.0', '0.0.1', '10.20.30', '1.0.0-rc.1', '1.0.0-alpha.beta', '1.0.0+build.5', '1.0.0-x.7.z.92'] as $v) {
            $this->assertTrue(Semver::valid($v), $v);
        }
        foreach (['1.0', 'v1.0.0', '01.0.0', '1.0.0-', '1.0.0-01', '1.0.0.0', '', 'latest', '../1.0.0'] as $v) {
            $this->assertFalse(Semver::valid($v), $v);
        }
    }

    public function test_semver_precedence_follows_the_spec(): void
    {
        $order = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.9.0', '1.10.0', '2.0.0'];
        for ($i = 1; $i < count($order); $i++) {
            $this->assertSame(-1, Semver::compare($order[$i - 1], $order[$i]), "{$order[$i - 1]} < {$order[$i]}");
            $this->assertSame(1, Semver::compare($order[$i], $order[$i - 1]));
        }
        $this->assertSame(0, Semver::compare('1.0.0+a', '1.0.0+b'));                       // build metadata is ignored
        $this->expectException(\InvalidArgumentException::class);
        Semver::compare('1.0', '1.0.0');
    }

    public function test_bump_names_the_biggest_changed_component(): void
    {
        $this->assertSame('major', Semver::bump('1.9.9', '2.0.0'));
        $this->assertSame('minor', Semver::bump('1.0.5', '1.1.0'));
        $this->assertSame('patch', Semver::bump('1.1.0', '1.1.1'));
        $this->assertSame('none', Semver::bump('1.0.0-rc.1', '1.0.0'));
        $this->assertSame('downgrade', Semver::bump('1.2.0', '1.1.0'));
        $this->assertTrue(Semver::isPrerelease('1.0.0-rc.1'));
        $this->assertFalse(Semver::isPrerelease('1.0.0'));
    }

    // ---- changelog parsing -----------------------------------------------------------------------------------------

    public function test_the_changelog_is_parsed_into_releases(): void
    {
        $c = new Changelog();
        $r = $c->parse(self::LOG);
        $this->assertSame([], $c->errors);
        $this->assertSame(['Unreleased', '1.2.0', '1.1.1', '1.1.0', '1.0.0'], array_column($r, 'version'));
        $this->assertTrue($r[0]['unreleased']);
        $this->assertSame('2026-09-01', $r[1]['date']);
        $this->assertSame(['Big feature continued on a second line'], $r[1]['sections']['Added']);   // continuation joined
        $this->assertSame('First stable release.', $r[4]['summary']);
        $this->assertSame(['Highlight one'], $r[4]['sections']['Highlights']);
        $this->assertSame(['Old endpoint removed'], $r[3]['breaking']);
        $this->assertSame([], $r[1]['breaking']);
    }

    public function test_breaking_is_detected_by_section_or_prefix(): void
    {
        $r = (new Changelog())->parse("## [Unreleased]\n### Changed\n- BREAKING: the format changed\n- **Breaking** the other thing\n- Not breaking at all\n- Unbreakable thing\n");
        $this->assertSame(['BREAKING: the format changed', '**Breaking** the other thing'], $r[0]['breaking']);
    }

    public function test_changelog_problems_are_reported(): void
    {
        $c = new Changelog();
        $c->parse("## [Unreleased] - 2026-01-01\n- x\n## [1.0] - 2026-01-01\n- x\n## [1.0.1]\n- x\n## [1.0.2] - 2026-13-45\n- x\n## [1.0.3] - 2026-01-01\n- x\n## [1.0.3] - 2026-01-02\n- x\n## [1.0.4] - 2026-01-01\n");
        $e = implode("\n", $c->errors);
        $this->assertStringContainsString('[Unreleased] must not have a date', $e);
        $this->assertStringContainsString("'1.0' is not a semantic version", $e);
        $this->assertStringContainsString('1.0.1 needs a valid date', $e);
        $this->assertStringContainsString('1.0.2 needs a valid date', $e);
        $this->assertStringContainsString('version 1.0.3 appears twice', $e);
        $this->assertStringContainsString('release 1.0.4 has no notes', $e);
    }

    // ---- notes between versions ------------------------------------------------------------------------------------

    public function test_between_is_exclusive_of_from_and_inclusive_of_to(): void
    {
        $n = $this->notes(self::LOG, '1.2.0', ['1.1.0' => true]);
        $b = $n->between('1.1.0', '1.2.0');
        $this->assertSame(['1.2.0', '1.1.1'], array_column($b['releases'], 'version'));
        $this->assertSame('minor', $b['boundary']);
        $this->assertSame([], $b['breaking']);                                  // 1.1.0 itself is already installed

        $b = $n->between('1.0.0', '1.2.0');
        $this->assertSame(['1.2.0', '1.1.1', '1.1.0'], array_column($b['releases'], 'version'));
        $this->assertSame([['version' => '1.1.0', 'entry' => 'Old endpoint removed']], $b['breaking']);
        $this->assertSame(['1.1.0'], array_keys($b['guides']));
        $this->assertSame(['1.2.0'], $b['missing_guides']);                      // a minor step without a guide is called out

        $this->assertSame(['1.1.1'], array_column($n->between('1.1.0', '1.1.1')['releases'], 'version'));   // `to` below the newest
        $this->assertSame('patch', $n->between('1.1.0', '1.1.1')['boundary']);
    }

    public function test_unreleased_is_included_only_on_request(): void
    {
        $n = $this->notes(self::LOG, '1.2.0');
        $this->assertNotContains('Unreleased', array_column($n->between('1.0.0', '1.2.0')['releases'], 'version'));
        $this->assertContains('Unreleased', array_column($n->between('1.0.0', '1.2.0', true)['releases'], 'version'));
    }

    public function test_fresh_installs_and_downgrades_have_no_notes(): void
    {
        $n = $this->notes(self::LOG, '1.2.0');
        $this->assertSame([], $n->between(null, '1.2.0')['releases']);
        $this->assertSame([], $n->between('garbage', '1.2.0')['releases']);
        $d = $n->between('1.2.0', '1.1.0');
        $this->assertSame(['downgrade', []], [$d['boundary'], $d['releases']]);
        $this->assertStringContainsString('older than the recorded', $n->summaryLines('1.2.0', '1.1.0')[0]);
    }

    public function test_summary_lines_for_app_upgrade(): void
    {
        $n = $this->notes(self::LOG, '1.2.0', ['1.1.0' => true]);
        $l = implode("\n", $n->summaryLines('1.0.0', '1.2.0'));
        $this->assertStringContainsString('3 release(s) between 1.0.0 and 1.2.0 (minor upgrade)', $l);
        $this->assertStringContainsString('1 breaking change(s)', $l);
        $this->assertStringContainsString('upgrade/1.1.0.md', $l);
        $this->assertSame([], $n->summaryLines('1.2.0', '1.2.0'));                // nothing to say when up to date
        $this->assertSame([], $n->summaryLines(null, '1.2.0'));
    }

    public function test_guide_paths_never_leave_the_guide_directory(): void
    {
        $n = $this->notes(self::LOG, '1.2.0');
        foreach (['../../etc/passwd', '..', 'a/b', '1.0', ''] as $evil) {
            $this->assertSame($this->dir.'/upgrade/unreleased.md', $n->guidePath($evil));
        }
    }

    // ---- the gate --------------------------------------------------------------------------------------------------

    private function problems(string $log, string $version, array $guides = []): string
    {
        return implode("\n", $this->notes($log, $version, $guides)->check());
    }

    public function test_a_consistent_changelog_passes(): void
    {
        $this->assertSame('', $this->problems(self::LOG, '1.2.0', ['1.2.0' => true, '1.1.0' => true]));
    }

    public function test_guides_are_required_for_breaking_and_for_minor_or_major_steps(): void
    {
        $p = $this->problems(self::LOG, '1.2.0');
        $this->assertStringContainsString('1.2.0 needs an upgrade guide', $p);       // minor step
        $this->assertStringContainsString('1.1.0 needs an upgrade guide', $p);       // breaking (and minor)
        $this->assertStringNotContainsString('1.1.1 needs', $p);                     // a patch needs none
        $this->assertStringNotContainsString('1.0.0 needs', $p);                     // the first release has nothing before it
    }

    public function test_a_prerelease_promotion_is_not_a_boundary(): void
    {
        $log = "## [Unreleased]\n## [1.0.0] - 2026-02-01\n- Final release.\n## [1.0.0-rc.1] - 2026-01-01\n- First.\n";
        $this->assertSame('', $this->problems($log, '1.0.0'));
    }

    public function test_unreleased_breaking_changes_need_a_guide_in_progress(): void
    {
        $log = "## [Unreleased]\n### Breaking\n- Gone\n## [1.0.0] - 2026-01-01\n- First.\n";
        $this->assertStringContainsString('write docs/upgrade/unreleased.md', $this->problems($log, '1.0.0'));
        $this->assertSame('', $this->problems($log, '1.0.0', ['unreleased' => true]));
    }

    public function test_version_file_order_and_first_section_are_checked(): void
    {
        $this->assertStringContainsString("VERSION is '1.1.1' but the newest released section is 1.2.0", $this->problems(self::LOG, '1.1.1', ['1.2.0' => true, '1.1.0' => true]));
        $swapped = strtr(self::LOG, ['## [1.2.0]' => '## [1.1.1]', '## [1.1.1]' => '## [1.2.0]']);
        $this->assertStringContainsString('newest first', $this->problems($swapped, '1.1.1', ['1.2.0' => true, '1.1.0' => true, '1.1.1' => true]));
        $this->assertStringContainsString('first section must be [Unreleased]', $this->problems("## [1.0.0] - 2026-01-01\n- x\n", '1.0.0'));
        $this->assertStringContainsString('missing or has no release sections', $this->problems("just text\n", '1.0.0'));
    }

    public function test_guides_must_be_complete_and_belong_to_a_release(): void
    {
        $p = $this->problems(self::LOG, '1.2.0', ['1.2.0' => true, '1.1.0' => "## Before you upgrade\n## Required actions\n", '9.9.9' => true]);
        $this->assertStringContainsString("1.1.0.md lacks the section '## Rollback'", $p);
        $this->assertStringContainsString('9.9.9.md belongs to no release', $p);
        $this->assertStringNotContainsString('1.2.0.md lacks', $p);
        $this->assertStringContainsString('1.2.0.md is unfinished', $this->problems(self::LOG, '1.2.0', ['1.2.0' => self::GOOD_GUIDE."\nTODO fill in\n", '1.1.0' => true]));
        $this->assertStringNotContainsString('README', $this->problems(self::LOG, '1.2.0', ['README' => 'x', 'TEMPLATE' => 'x', '1.2.0' => true, '1.1.0' => true]));
    }

    public function test_unfinished_release_entries_fail_the_gate(): void
    {
        $log = str_replace('- Tiny fix', '- TBD describe this', self::LOG);
        $this->assertStringContainsString('1.1.1: an entry in', $this->problems($log, '1.2.0', ['1.2.0' => true, '1.1.0' => true]));
        $unreleased = str_replace('- Next thing', '- TODO later', self::LOG);
        $this->assertSame('', $this->problems($unreleased, '1.2.0', ['1.2.0' => true, '1.1.0' => true]));   // work in progress is allowed
    }

    public function test_this_repository_passes_its_own_gate(): void
    {
        $this->assertSame([], ReleaseNotes::fromApp()->check());
        $this->assertSame(0, Artisan::call('release:check'));
        $this->assertTrue(Semver::valid(trim((string) file_get_contents(base_path('VERSION')))));
    }

    // ---- drafting from conventional commits ------------------------------------------------------------------------

    public function test_conventional_commits_become_draft_entries(): void
    {
        $d = (new ConventionalCommits())->draft(<<<'LOG'
feat(billing): add proration.
fix: stop double invoices
fix(security): reject forged tokens
perf(search): faster lookups
refactor: tidy
feat(api)!: remove the legacy endpoint
chore: bump deps
docs: update readme
ci: cache composer
BREAKING CHANGE: config key renamed
Merge branch 'x'
updated stuff
LOG);
        $this->assertSame(['Breaking', 'Added', 'Changed', 'Fixed', 'Security'], array_keys($d['sections']));
        $this->assertSame(['Remove the legacy endpoint (api)', 'config key renamed'], $d['sections']['Breaking']);
        $this->assertSame(['Add proration (billing)'], $d['sections']['Added']);
        $this->assertSame(['Reject forged tokens (security)'], $d['sections']['Security']);
        $this->assertSame('major', $d['bump']);
        $this->assertSame(3, $d['skipped']);
        $this->assertSame(['updated stuff'], $d['unparsed']);
        $this->assertStringContainsString("### Added\n- Add proration (billing)", (new ConventionalCommits())->markdown($d));
    }

    public function test_the_suggested_bump_follows_the_biggest_change(): void
    {
        $cc = new ConventionalCommits();
        $this->assertSame('minor', $cc->draft("fix: a\nfeat: b")['bump']);
        $this->assertSame('patch', $cc->draft("fix: a\nrefactor: b")['bump']);
        $this->assertSame('none', $cc->draft("chore: a\ndocs: b")['bump']);
    }

    public function test_the_draft_command_reads_a_file(): void
    {
        file_put_contents($this->dir.'/log.txt', "feat: new thing\nfix: old bug\n");
        $this->assertSame(0, Artisan::call('release:draft', ['file' => $this->dir.'/log.txt']));
        $out = Artisan::output();
        $this->assertStringContainsString('- New thing', $out);
        $this->assertStringContainsString('Suggested bump: minor', $out);
        $this->assertSame(1, Artisan::call('release:draft', ['file' => $this->dir.'/missing.txt']));
    }

    public function test_strict_mode_is_the_commit_message_check(): void
    {
        file_put_contents($this->dir.'/ok.txt', "feat(billing)!: pay links
fix: a bug
ci(security): scan
Merge pull request #3 from x/y
");
        $this->assertSame(0, Artisan::call('release:draft', ['file' => $this->dir.'/ok.txt', '--strict' => true]));

        file_put_contents($this->dir.'/bad.txt', "feat: fine
initial commit
Update stuff
");
        $this->assertSame(1, Artisan::call('release:draft', ['file' => $this->dir.'/bad.txt', '--strict' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('Not a conventional commit: initial commit', $out);
        $this->assertStringContainsString('Not a conventional commit: Update stuff', $out);
        $this->assertSame(0, Artisan::call('release:draft', ['file' => $this->dir.'/bad.txt']), 'without --strict it only warns');
    }

    public function test_every_commit_of_this_branch_is_conventional(): void
    {
        $base = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' merge-base HEAD origin/main 2>/dev/null'));
        if ($base === '') {
            $this->markTestSkipped('No origin/main to compare with.');
        }
        file_put_contents($this->dir.'/branch.txt', (string) shell_exec('git -C '.escapeshellarg(base_path())." log --no-merges --format=%s {$base}..HEAD"));
        $this->assertSame(0, Artisan::call('release:draft', ['file' => $this->dir.'/branch.txt', '--strict' => true]), Artisan::output());
    }

    // ---- commands against the repository --------------------------------------------------------------------------

    public function test_release_notes_command_shows_changes_and_guides(): void
    {
        $this->assertSame(0, Artisan::call('release:notes', ['from' => '0.9.0', 'to' => '1.0.0']));
        $out = Artisan::output();
        $this->assertStringContainsString('0.9.0 -> 1.0.0: major upgrade, 2 release(s)', $out);
        $this->assertStringContainsString('BREAKING [1.0.0] Platform staff MFA', $out);
        $this->assertStringContainsString('Upgrade guide 1.0.0', $out);

        Artisan::call('release:notes', ['from' => '0.9.0', 'to' => '1.0.0', '--json' => true]);
        $j = json_decode(Artisan::output(), true);
        $this->assertSame('major', $j['boundary']);

        Artisan::call('release:notes', ['from' => '1.2.0', 'to' => '1.0.0']);
        $this->assertStringContainsString('downgrade', Artisan::output());
    }

    public function test_release_notes_without_a_recorded_release_says_so(): void
    {
        Artisan::call('release:notes');
        $this->assertStringContainsString('fresh install', Artisan::output());
    }

    public function test_app_upgrade_prints_the_notes_before_changing_anything(): void
    {
        ReleaseLog::record('0.9.0');
        Artisan::call('app:upgrade', ['--dry-run' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('Release notes:', $out);
        $this->assertStringContainsString('(major upgrade)', $out);
        $this->assertStringContainsString('Dry run: nothing was changed.', $out);
    }

    // ---- API -------------------------------------------------------------------------------------------------------

    public function test_the_releases_api_needs_a_login_and_lists_only_released_versions(): void
    {
        $this->getJson('/api/v1/releases')->assertUnauthorized();
        $this->getJson('/api/v1/releases/1.0.0-rc.1')->assertUnauthorized();

        $u = $this->userWithPermissions([]);
        $r = $this->actingAs($u)->getJson('/api/v1/releases')->assertOk();
        $this->assertSame(config('app.version'), $r->json('data.current'));
        $this->assertSame(['1.0.0', '1.0.0-rc.1'], array_column($r->json('data.releases'), 'version'));
        [$final, $rc] = $r->json('data.releases');
        $this->assertFalse($final['prerelease']);
        $this->assertArrayHasKey('Breaking', $final['sections']);
        $this->assertTrue($rc['prerelease']);
        $this->assertArrayHasKey('Highlights', $rc['sections']);
    }

    public function test_the_releases_api_filters_and_validates(): void
    {
        $u = $this->userWithPermissions([]);
        $this->actingAs($u)->getJson('/api/v1/releases?since=1.0.0')->assertOk()->assertJsonCount(0, 'data.releases');
        $this->actingAs($u)->getJson('/api/v1/releases?since=1.0.0-rc.1')->assertOk()->assertJsonCount(1, 'data.releases');
        $this->actingAs($u)->getJson('/api/v1/releases?since=0.1.0')->assertOk()->assertJsonCount(2, 'data.releases');
        $this->actingAs($u)->getJson('/api/v1/releases?since=latest')->assertStatus(422);
    }

    public function test_a_release_shows_its_guide_and_unknown_or_hostile_versions_are_404(): void
    {
        $u = $this->userWithPermissions([]);
        $this->actingAs($u)->getJson('/api/v1/releases/1.0.0-rc.1')->assertOk()->assertJsonPath('data.version', '1.0.0-rc.1')->assertJsonPath('data.has_upgrade_guide', false)->assertJsonPath('data.upgrade_guide', null);
        $this->actingAs($u)->getJson('/api/v1/releases/1.0.0')->assertOk()->assertJsonPath('data.has_upgrade_guide', true);
        foreach (['9.9.9', 'Unreleased', 'unreleased', '1.0.1', '..', '..%2F..%2F.env', 'README', 'TEMPLATE'] as $v) {
            $this->actingAs($u)->getJson('/api/v1/releases/'.$v)->assertNotFound();
        }
    }
}
