<?php

namespace Tests\Feature\Legacy;

use App\Livewire\Migration\ReviewIndex;
use App\Models\AuditEvent;
use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyPairSuggestion;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\User;
use App\Services\Legacy\LegacyException;
use App\Services\Legacy\PairReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class ReviewTest extends LegacyTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A same-id, same-phone EN/AR pair run through stage/apply/suggest; returns the suggestion. */
    private function pair(array $en = [], array $ar = []): LegacyPairSuggestion
    {
        $this->en($en + ['id' => 1, 'phone' => '01000000001', 'name' => 'Pair Clinic English', 'map_url' => 'https://m.test/?q=30,31']);
        $this->ar($ar + ['id' => 1, 'phone' => '01000000001', 'name' => 'عيادة الزوج العربية', 'map_url' => 'https://m.test/?q=30,31']);
        $this->admin = $this->admin();
        $this->runPipeline($this->admin);
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        return LegacyPairSuggestion::query()->firstOrFail();
    }

    private function service(): PairReviewService
    {
        return app(PairReviewService::class);
    }

    /** Providers/translations/maps as comparable content (ids and timestamps excluded where they legitimately change). */
    private function state(): array
    {
        return [
            Provider::query()->withTrashed()->orderBy('id')->get()->map(fn (Provider $p) => [
                $p->id, $p->phone, $p->map_url, (string) $p->latitude, $p->provider_type_id, $p->deleted_at === null,
            ])->all(),
            ProviderTranslation::query()->orderBy('id')->get(['id', 'provider_id', 'locale', 'name', 'address', 'city'])->toArray(),
            LegacyIdMap::query()->orderBy('id')->get(['id', 'provider_id', 'status', 'match_method'])->toArray(),
        ];
    }

    public function test_approve_merges_the_arabic_translation_onto_the_english_provider(): void
    {
        $s = $this->pair();
        $left = $s->left;
        $right = $s->right;
        $enId = $left->provider_id;
        $arId = $right->provider_id;
        $arTranslationId = ProviderTranslation::query()->where('provider_id', $arId)->value('id');

        $this->service()->approve($this->admin, $s);

        $en = Provider::query()->with('translations')->findOrFail($enId);
        $this->assertEqualsCanonicalizing(['ar', 'en'], $en->translations->pluck('locale')->all());
        $this->assertSame($arTranslationId, $en->translation('ar')->id, 'the translation row is moved, not copied');
        $this->assertSame('عيادة الزوج العربية', $en->translation('ar')->name);
        $this->assertTrue(Provider::query()->withTrashed()->findOrFail($arId)->trashed(), 'the emptied Arabic provider is soft-deleted');
        $this->assertSame(0, ProviderTranslation::query()->where('provider_id', $arId)->count());
        $this->assertSame(1, Provider::query()->count());

        $left->refresh();
        $right->refresh();
        foreach ([$left, $right] as $map) {
            $this->assertSame(['approved', 'manual_review', $this->admin->id], [$map->status, $map->match_method, $map->reviewed_by]);
            $this->assertNotNull($map->reviewed_at);
        }
        $this->assertSame($enId, $right->provider_id);
        $this->assertSame($arId, $right->review_evidence['prior_provider_id'], 'the prior provider id is preserved for reversal');
        $this->assertSame($enId, $right->review_evidence['merged_into_provider_id']);
        $this->assertSame($arTranslationId, $right->review_evidence['moved_translation_id']);
        $this->assertSame(['same_coordinates', 'same_map_url', 'same_phone_normalized', 'same_source_id'], $right->review_evidence['signals']);
        $this->assertSame('approved', $s->fresh()->status);
        $this->assertSame($this->admin->id, $s->fresh()->reviewed_by);
        $this->assertGreaterThan(1, $en->revision, 'a merge bumps the optimistic-concurrency revision');
    }

    public function test_conflicting_values_keep_the_english_canonical_and_preserve_the_arabic_ones_for_review(): void
    {
        $s = $this->pair(
            ['phone' => '01000000001', 'map_url' => 'https://m.test/?q=30,31', 'website' => 'https://en.example.test'],
            ['phone' => '0100 000 0001', 'map_url' => 'https://other.test/?q=30,31', 'website' => 'https://ar.example.test'],
        );
        $enId = $s->left->provider_id;
        $arId = $s->right->provider_id;

        $this->service()->approve($this->admin, $s);

        $en = Provider::query()->findOrFail($enId);
        $this->assertSame(['01000000001', 'https://m.test/?q=30,31', 'https://en.example.test'], [$en->phone, $en->map_url, $en->website], 'canonical takes the English provider values');

        $evidence = $s->right->fresh()->review_evidence;
        $this->assertEquals([
            'phone' => '0100 000 0001', 'map_url' => 'https://other.test/?q=30,31', 'website' => 'https://ar.example.test',
        ], array_intersect_key($evidence['discarded_ar_values'], array_flip(['phone', 'map_url', 'website'])));

        // The discarded Arabic provider row keeps its own raw values too (soft-deleted, not rewritten).
        $ar = Provider::query()->withTrashed()->findOrFail($arId);
        $this->assertSame(['0100 000 0001', 'https://other.test/?q=30,31'], [$ar->phone, $ar->map_url]);

        // The review page shows the conflicting values to the admin.
        $this->actingAs($this->admin)->get(route('migration.review.index', ['status' => 'approved']))
            ->assertOk()->assertSee('https://other.test/?q=30,31')->assertSee('0100 000 0001');
    }

    public function test_undo_restores_the_exact_pre_approval_state(): void
    {
        $s = $this->pair(['phone' => '01000000001'], ['phone' => '0100 000 0001', 'map_url' => 'https://other.test/?q=1,2', 'website' => 'https://ar.example.test']);
        $before = $this->state();

        $this->service()->approve($this->admin, $s);
        $this->assertNotSame($before, $this->state());

        $this->service()->undo($this->admin, $s->fresh());

        $this->assertSame($before, $this->state(), 'providers, translations and id maps are exactly as before');
        $s->refresh();
        $this->assertSame(['suggested', null], [$s->status, $s->reviewed_by]);
        $maps = LegacyIdMap::query()->get();
        $this->assertSame(['unpaired', 'unpaired'], $maps->pluck('status')->all());
        $this->assertSame('undone', $maps[1]->review_evidence['action']);
        $this->assertSame(2, Provider::query()->count());

        // and the pair can be approved again afterwards
        $this->service()->approve($this->admin, $s->fresh());
        $this->assertSame(1, Provider::query()->count());
    }

    public function test_reject_marks_the_suggestion_and_keeps_both_providers_unpaired(): void
    {
        $s = $this->pair();
        $before = $this->state();

        $this->service()->reject($this->admin, $s);

        $this->assertSame('rejected', $s->fresh()->status);
        $this->assertSame($this->admin->id, $s->fresh()->reviewed_by);
        $this->assertSame($before, $this->state());
        $this->assertSame(['unpaired'], LegacyIdMap::query()->pluck('status')->unique()->all());

        $this->expectException(LegacyException::class); // cannot be approved after rejection
        $this->service()->approve($this->admin, $s->fresh());
    }

    public function test_a_suggestion_cannot_be_reviewed_twice_and_unapproved_pairs_cannot_be_undone(): void
    {
        $s = $this->pair();

        try {
            $this->service()->undo($this->admin, $s);
            $this->fail('undo of a pending suggestion must be refused');
        } catch (LegacyException) {
        }

        $this->service()->approve($this->admin, $s);
        $snapshot = $this->state();

        try {
            $this->service()->approve($this->admin, $s->fresh());
            $this->fail('second approve must be refused');
        } catch (LegacyException) {
        }
        $this->assertSame($snapshot, $this->state());
    }

    public function test_approve_is_refused_when_either_provider_changed_underneath(): void
    {
        $s = $this->pair();
        Provider::query()->findOrFail($s->right->provider_id)->delete(); // Arabic provider deleted meanwhile
        $snapshot = $this->state();

        $this->expectException(LegacyException::class);
        try {
            $this->service()->approve($this->admin, $s);
        } finally {
            $this->assertSame($snapshot, $this->state());
            $this->assertSame('suggested', $s->fresh()->status);
        }
    }

    public function test_undo_is_refused_when_the_merged_translation_was_removed_after_approval(): void
    {
        $s = $this->pair();
        $this->service()->approve($this->admin, $s);
        ProviderTranslation::query()->where('locale', 'ar')->delete();

        $this->expectException(LegacyException::class);
        $this->service()->undo($this->admin, $s->fresh());
    }

    public function test_only_admins_may_review_viewers_and_editors_and_inactive_admins_are_denied(): void
    {
        $s = $this->pair();
        $snapshot = $this->state();

        foreach ([User::factory()->viewer()->create(), User::factory()->editor()->create(), User::factory()->admin()->inactive()->create()] as $user) {
            foreach (['approve', 'reject', 'undo'] as $action) {
                try {
                    $this->service()->{$action}($user, $s);
                    $this->fail("{$action} must be denied for {$user->role?->value}");
                } catch (AuthorizationException) {
                }
            }
        }

        $this->assertSame($snapshot, $this->state());
        $this->assertSame('suggested', $s->fresh()->status);
        $this->assertGreaterThanOrEqual(3, AuditEvent::query()->where('action', 'authorization.denied')->count());
    }

    public function test_the_review_page_is_admin_only_and_guests_are_redirected(): void
    {
        $this->pair();

        $this->get(route('migration.review.index'))->assertRedirect(route('login'));

        foreach ([User::factory()->viewer()->create(), User::factory()->editor()->create()] as $user) {
            $this->actingAs($user)->get(route('migration.review.index'))->assertForbidden();
        }

        $this->actingAs($this->admin)->get(route('migration.review.index'))->assertOk();
    }

    public function test_livewire_actions_authorize_themselves(): void
    {
        $s = $this->pair();

        foreach ([User::factory()->viewer()->create(), User::factory()->editor()->create()] as $user) {
            Livewire::actingAs($user)->test(ReviewIndex::class)->assertForbidden();
        }

        // A component mounted by an admin must still re-check on every action (update requests skip route middleware).
        $editor = User::factory()->editor()->create();
        foreach (['approve', 'reject', 'undo'] as $action) {
            $this->actingAs($this->admin);
            $component = Livewire::test(ReviewIndex::class)->assertOk();
            $this->actingAs($editor);
            $component->call($action, $s->id)->assertForbidden();
        }

        $this->assertSame('suggested', $s->fresh()->status);
        $this->assertSame(2, Provider::query()->count());
    }

    public function test_admin_can_approve_undo_and_reject_through_the_livewire_page(): void
    {
        $s = $this->pair();
        $other = $this->pairSecond();

        $component = Livewire::actingAs($this->admin)->test(ReviewIndex::class)
            ->assertSee('Pair Clinic English')->assertSee('عيادة الزوج العربية')
            ->assertSee('Same phone number')->assertSee('Same legacy id');

        $component->call('approve', $s->id)->assertHasNoErrors();
        $this->assertSame('approved', $s->fresh()->status);
        $this->assertSame(['approved', 'suggested'], [$s->fresh()->status, $other->fresh()->status]);

        $component->set('status', 'approved')->assertSee('Undo approval')->call('undo', $s->id);
        $this->assertSame('suggested', $s->fresh()->status);

        $component->set('status', 'suggested')->call('reject', $other->id);
        $this->assertSame('rejected', $other->fresh()->status);

        $actions = AuditEvent::query()->where('action', 'like', 'migration.pair.%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['migration.pair.approved', 'migration.pair.undone', 'migration.pair.rejected'], $actions);
    }

    /** A second, independent pair added after the first pipeline run. */
    private function pairSecond(): LegacyPairSuggestion
    {
        $this->en(['id' => 20, 'phone' => '01555555555', 'name' => 'Second Clinic English', 'map_url' => 'https://m.test/?q=29,30']);
        $this->ar(['id' => 20, 'phone' => '01555555555', 'name' => 'العيادة الثانية', 'map_url' => 'https://m.test/?q=29,30']);
        // staged batch is applied already: roll forward using a fresh batch is not allowed, so create the maps directly.
        $batch = LegacyMigrationBatch::query()->firstOrFail();
        $maps = [];
        foreach (['providers' => ['en', 'Second Clinic English'], 'arabic_providers' => ['ar', 'العيادة الثانية']] as $table => [$locale, $name]) {
            $provider = Provider::query()->create(['provider_type_id' => Provider::query()->value('provider_type_id'), 'phone' => '01555555555', 'is_active' => true]);
            $provider->translations()->create(['locale' => $locale, 'name' => $name, 'address' => 'x']);
            $maps[$table] = LegacyIdMap::query()->create([
                'source_table' => $table, 'source_id' => 20, 'source_locale' => $locale, 'provider_id' => $provider->id,
                'status' => 'unpaired', 'snapshot_sha256' => self::SHA, 'migration_batch_id' => $batch->id,
            ]);
        }

        return LegacyPairSuggestion::query()->create([
            'migration_batch_id' => $batch->id, 'left_map_id' => $maps['providers']->id, 'right_map_id' => $maps['arabic_providers']->id,
            'signals' => ['same_phone_normalized', 'same_source_id'], 'signal_count' => 2, 'status' => 'suggested',
        ]);
    }

    public function test_review_audit_events_carry_ids_and_signal_names_but_no_provider_values(): void
    {
        $s = $this->pair(['phone' => '01234567890', 'name' => 'Very Identifiable Name'], ['phone' => '01098765432']);

        $this->service()->approve($this->admin, $s);
        $this->service()->undo($this->admin, $s->fresh());
        $this->service()->reject($this->admin, $s->fresh());

        $events = AuditEvent::query()->where('action', 'like', 'migration.pair.%')->get();
        $this->assertCount(3, $events);
        $json = json_encode($events->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['01234567890', '01098765432', 'Very Identifiable Name', 'عيادة الزوج', 'm.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $approved = $events->firstWhere('action', 'migration.pair.approved');
        $this->assertContains('phone', $approved->metadata['conflict_fields']);
        $this->assertSame(['same_coordinates', 'same_map_url', 'same_source_id'], $approved->metadata['signals']);
    }

    public function test_page_output_is_escaped(): void
    {
        $this->pair(['name' => '<script>alert("x")</script>']);

        $this->actingAs($this->admin)->get(route('migration.review.index'))
            ->assertOk()->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_no_code_path_approves_automatically(): void
    {
        $this->pair();

        $this->assertSame(0, LegacyIdMap::query()->where('status', 'approved')->count());
        $this->assertSame(0, LegacyPairSuggestion::query()->where('status', 'approved')->count());
        $this->assertSame(0, DB::table('legacy_id_maps')->whereNotNull('reviewed_by')->count());
        $this->assertSame(2, Provider::query()->whereNull('deleted_at')->count());
    }
}
