<?php

namespace Tests\Feature\Transfers;

use App\Enums\Role;
use App\Jobs\ApplyImportJob;
use App\Models\AuditEvent;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Services\Transfers\ImportActionRejected;
use App\Services\Transfers\ImportApplier;
use App\Services\Transfers\ImportChunkWritten;
use App\Services\Transfers\ImportJobService;
use App\Services\Transfers\ImportLockBusy;
use App\Services\Transfers\ImportNotApplicable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;

class ImportApplyTest extends TransfersTestCase
{
    /** @return list<array<string, mixed>> */
    private function createRows(int $count, string $prefix = 'Lab'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->row(['provider_type' => 'laboratory', 'name' => "$prefix $i", 'address' => "Street $i", 'phone' => '0100'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }

        return $rows;
    }

    private function failOnChunk(int $index): \Closure
    {
        $armed = true;
        Event::listen(ImportChunkWritten::class, function (ImportChunkWritten $e) use (&$armed, $index) {
            if ($armed && $e->chunkIndex === $index) {
                throw new RuntimeException('simulated crash');
            }
        });

        return function () use (&$armed) {
            $armed = false;
        };
    }

    // ------------------------------------------------------------------ happy path and audit

    public function test_a_confirmed_import_creates_providers_with_the_actor_audit_and_one_applied_event(): void
    {
        $editor = $this->editor();
        $job = $this->importAndApply($editor, $this->workbook($this->createRows(3)), ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(3, $job->applied_count);
        $this->assertSame(3, Provider::count());
        $provider = Provider::first();
        $this->assertSame(1, $provider->revision);
        $this->assertSame($editor->id, $provider->created_by);
        $this->assertSame($editor->id, $provider->updated_by);
        $this->assertSame($this->type->id, $provider->provider_type_id);

        foreach (['import.uploaded', 'import.confirmed', 'import.applied'] as $action) {
            $this->assertSame(1, AuditEvent::where('action', $action)->count(), $action);
        }
        $this->assertSame(0, AuditEvent::where('action', 'provider.created')->count(), 'imports write one job-level audit event, not one per row');
        $applied = AuditEvent::where('action', 'import.applied')->first();
        $this->assertSame(3, $applied->metadata['created']);
        $this->assertSame($editor->id, $applied->actor_id);
        $this->assertNotNull($job->confirmed_at);
        $this->assertSame($editor->id, $job->confirmed_by);
    }

    public function test_updates_bump_the_revision_once_per_changed_provider_and_record_updated_by(): void
    {
        $p = $this->provider(['en' => ['name' => 'Old', 'address' => 'A']], ['phone' => '0100']);
        $editor = $this->editor();

        $this->importAndApply($editor, $this->workbook([$this->row(['provider_id' => $p->id, 'name' => 'New', 'city' => 'Cairo', 'phone' => '0111'])]), ImportJob::MODE_UPDATE_ONLY);

        $p->refresh();
        $this->assertSame(2, $p->revision);
        $this->assertSame($editor->id, $p->updated_by);
        $this->assertSame('0111', $p->phone);
    }

    public function test_language_rows_of_one_provider_do_not_conflict_with_each_other_even_across_chunks(): void
    {
        config(['providers.imports.chunk_size' => 1]);
        $p = $this->provider(['en' => ['name' => 'E', 'address' => 'A'], 'ar' => ['name' => 'ع', 'address' => 'ع']], ['phone' => '0100']);
        $path = $this->workbook([
            $this->row(['provider_id' => $p->id, 'locale' => 'en', 'name' => 'E2', 'phone' => '0199']),
            $this->row(['provider_id' => $p->id, 'locale' => 'ar', 'name' => 'ع2', 'phone' => '0199']),
        ]);

        $job = $this->importAndApply($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->failure_reason);
        $p->refresh()->load('translations');
        $this->assertSame('E2', $p->translation('en')->name);
        $this->assertSame('ع2', $p->translation('ar')->name);
        $this->assertSame('0199', $p->phone);
        $this->assertSame(3, $p->revision, 'each changing row bumps once');
    }

    // ------------------------------------------------------------------ re-import

    public function test_importing_the_same_create_file_twice_never_duplicates_records(): void
    {
        $editor = $this->editor();
        $path = $this->workbook($this->createRows(4));
        $first = $this->importAndApply($editor, $path, ImportJob::MODE_CREATE_ONLY);
        $this->assertSame(ImportJob::STATUS_COMPLETED, $first->status);

        $second = $this->startImport($editor, $path, ImportJob::MODE_CREATE_ONLY);
        $this->assertSame(ImportJob::STATUS_READY, $second->status);
        $this->actingAs($editor)->post(route('imports.confirm', $second))->assertSessionHasErrors('import');

        $this->assertSame(4, Provider::count());
        $this->assertSame(ImportJob::STATUS_READY, $second->fresh()->status);
        $this->expectException(ImportActionRejected::class);
        app(ImportJobService::class)->confirm($editor, $second);
    }

    public function test_re_importing_an_update_file_changes_nothing_the_second_time(): void
    {
        $p = $this->provider(['en' => ['name' => 'Old', 'address' => 'A']]);
        $editor = $this->editor();
        $path = $this->workbook([$this->row(['provider_id' => $p->id, 'name' => 'New', 'address' => 'B'])]);

        $this->importAndApply($editor, $path, ImportJob::MODE_UPSERT);
        $this->assertSame(2, $p->fresh()->revision);
        $second = $this->importAndApply($editor, $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        $this->assertSame(0, $second->update_count);
        $this->assertSame(1, $second->unchanged_count);
        $this->assertSame(2, $p->fresh()->revision, 'no mutation on the second import');
    }

    // ------------------------------------------------------------------ interruption and resume

    public function test_an_interrupted_import_keeps_committed_chunks_and_resumes_with_exact_counts(): void
    {
        config(['providers.imports.chunk_size' => 5]);
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(23)), ImportJob::MODE_CREATE_ONLY);
        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $disarm = $this->failOnChunk(2);

        try {
            app(ImportJobService::class)->confirm($editor, $job);
            $this->fail('the simulated crash should surface');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated crash', $e->getMessage());
        }

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_INTERRUPTED, $job->status, 'partial progress is never labelled completed');
        $this->assertSame(10, $job->applied_count, 'two chunks were committed, the crashed chunk rolled back');
        $this->assertSame(10, Provider::count(), 'committed progress == real progress');
        $this->assertSame(10, ImportRow::where('import_job_id', $job->id)->where('apply_status', 'applied')->count());
        $this->assertSame(13, ImportRow::where('import_job_id', $job->id)->where('apply_status', 'pending')->count());
        $this->assertSame(1, AuditEvent::where('action', 'import.failed')->count());
        $this->assertSame(0, AuditEvent::where('action', 'import.applied')->count());
        $this->assertSame('RuntimeException', $job->failure_reason, 'no raw exception message is stored');

        // The owner sees the partially applied state explicitly.
        $this->actingAs($editor)->get(route('imports.show', $job))
            ->assertOk()->assertSee(__('transfers.status.interrupted'))
            ->assertSee(__('transfers.imports.partial_note', ['applied' => 10, 'total' => 23]));

        $disarm();
        app(ImportJobService::class)->retry($editor, $job);

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(23, $job->applied_count);
        $this->assertSame(23, Provider::count(), 'no duplicates after resume');
        $this->assertSame(23, ProviderTranslation::distinct('name')->count('name'));
        $this->assertSame(0, ImportRow::where('import_job_id', $job->id)->where('apply_status', 'pending')->count());
        $this->assertSame(1, AuditEvent::where('action', 'import.applied')->count());
        $this->assertSame(23, ImportRow::where('import_job_id', $job->id)->whereNotNull('provider_id')->count());
    }

    public function test_a_crash_before_any_chunk_commits_marks_the_job_failed_not_interrupted(): void
    {
        config(['providers.imports.chunk_size' => 5]);
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(8)), ImportJob::MODE_CREATE_ONLY);
        $this->failOnChunk(0);

        try {
            app(ImportJobService::class)->confirm($editor, $job);
        } catch (RuntimeException) {
        }

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_FAILED, $job->status);
        $this->assertSame(0, $job->applied_count);
        $this->assertSame(0, Provider::count());
    }

    public function test_a_failure_cannot_downgrade_a_completed_job(): void
    {
        $job = $this->importAndApply($this->editor(), $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);

        app(ImportApplier::class)->markFailed($job, new RuntimeException('late'));

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->fresh()->status);
    }

    // ------------------------------------------------------------------ locking

    public function test_a_held_global_lock_makes_apply_back_off_without_writing_and_without_deadlock(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(3)), ImportJob::MODE_CREATE_ONLY);
        $this->assertTrue(Cache::lock(ImportApplier::LOCK_KEY, 60)->get()); // "another import" holds the lock

        // confirm() dispatches synchronously; the job must release itself, not fail or block.
        app(ImportJobService::class)->confirm($editor, $job);

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_APPLYING, $job->status);
        $this->assertSame(0, Provider::count());
        $this->assertSame(0, ImportRow::where('import_job_id', $job->id)->where('apply_status', 'applied')->count());
        $this->assertSame(0, AuditEvent::where('action', 'import.failed')->count());

        try {
            app(ImportApplier::class)->apply($job);
            $this->fail('apply() must report the busy lock');
        } catch (ImportLockBusy) {
        }

        // The job object releases itself back to the queue with the configured delay.
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(config('providers.imports.lock_release_delay'));
        $apply = new ApplyImportJob($job->id);
        $apply->setJob($queueJob);
        $apply->handle(app(ImportApplier::class));

        // Once the other import is done the very same job completes.
        Cache::lock(ImportApplier::LOCK_KEY)->forceRelease();
        ApplyImportJob::dispatchSync($job->id);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->fresh()->status);
        $this->assertSame(3, Provider::count());
        $this->assertTrue(Cache::lock(ImportApplier::LOCK_KEY, 5)->get(), 'the lock is released after the run');
    }

    public function test_two_overlapping_imports_serialize_and_neither_loses_updates(): void
    {
        $p = $this->provider(['en' => ['name' => 'Base', 'address' => 'A']]);
        $a = $this->editor();
        $b = $this->editor();
        $jobA = $this->startImport($a, $this->workbook([$this->row(['provider_id' => $p->id, 'city' => 'Cairo'])]), ImportJob::MODE_UPDATE_ONLY);
        $jobB = $this->startImport($b, $this->workbook([$this->row(['provider_id' => $p->id, 'district' => 'Maadi'])]), ImportJob::MODE_UPDATE_ONLY);

        $lock = Cache::lock(ImportApplier::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        app(ImportJobService::class)->confirm($a, $jobA); // blocked while "A" holds the lock
        $this->assertSame(0, ProviderTranslation::whereNotNull('city')->count());
        $lock->release();

        ApplyImportJob::dispatchSync($jobA->id);
        // B was previewed against revision 1; A bumped it to 2, so B must conflict, never overwrite silently.
        app(ImportJobService::class)->confirm($b, $jobB);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $jobA->fresh()->status);
        $this->assertSame(ImportJob::STATUS_CONFLICT, $jobB->fresh()->status);
        $t = $p->fresh()->load('translations')->translation('en');
        $this->assertSame('Cairo', $t->city);
        $this->assertNull($t->district, 'the stale second import must not be applied');
    }

    // ------------------------------------------------------------------ conflicts

    public function test_a_provider_changed_after_the_preview_stops_the_job_with_a_conflict_and_keeps_earlier_chunks(): void
    {
        config(['providers.imports.chunk_size' => 2]);
        $providers = [];
        foreach (range(1, 4) as $i) {
            $providers[$i] = $this->provider(['en' => ['name' => "P$i", 'address' => "A$i"]]);
        }
        $editor = $this->editor();
        $rows = array_map(fn ($p) => $this->row(['provider_id' => $p->id, 'city' => 'Changed']), $providers);
        $job = $this->startImport($editor, $this->workbook(array_values($rows)), ImportJob::MODE_UPDATE_ONLY);
        $this->assertSame(ImportJob::STATUS_READY, $job->status);

        // Someone else edits provider 3 between preview and confirm.
        $providers[3]->translations()->first()->update(['name' => 'Edited elsewhere']);
        $providers[3]->increment('revision');

        app(ImportJobService::class)->confirm($editor, $job);

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_CONFLICT, $job->status);
        $this->assertSame(2, $job->applied_count);
        $this->assertSame('Changed', $providers[1]->translations()->first()->city);
        $this->assertSame('Changed', $providers[2]->translations()->first()->city);
        $this->assertNull($providers[3]->translations()->first()->city);
        $this->assertNull($providers[4]->translations()->first()->city, 'the conflicting chunk is rolled back as a whole');
        $this->assertSame(1, ImportRow::where('import_job_id', $job->id)->where('apply_status', 'conflict')->count());
        $this->assertSame(ImportRow::where('import_job_id', $job->id)->where('apply_status', 'conflict')->value('row_number'), 4);
        $this->assertDatabaseHas('audit_events', ['action' => 'import.failed', 'result' => 'failure']);

        // A conflict is final: it cannot be resumed, only cancelled and re-previewed.
        $this->expectException(ImportActionRejected::class);
        app(ImportJobService::class)->retry($editor, $job);
    }

    public function test_a_provider_deleted_after_the_preview_is_a_conflict(): void
    {
        $p = $this->provider(['en' => ['name' => 'P', 'address' => 'A']]);
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook([$this->row(['provider_id' => $p->id, 'city' => 'X'])]), ImportJob::MODE_UPDATE_ONLY);
        $p->delete();

        app(ImportJobService::class)->confirm($editor, $job);

        $this->assertSame(ImportJob::STATUS_CONFLICT, $job->fresh()->status);
        $this->assertNull($p->translations()->first()->city);
    }

    // ------------------------------------------------------------------ guards

    public function test_apply_without_a_confirmed_ready_preview_is_rejected_with_zero_writes(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(2)), ImportJob::MODE_CREATE_ONLY);
        $before = $this->canonicalCounts();
        $this->assertSame(ImportJob::STATUS_READY, $job->status);

        // 1) job dispatched directly, never confirmed
        ApplyImportJob::dispatchSync($job->id);
        $this->assertSame($before, $this->canonicalCounts());
        $this->assertSame(ImportJob::STATUS_READY, $job->fresh()->status);

        // 2) handler called directly
        try {
            app(ImportApplier::class)->apply($job);
            $this->fail('unconfirmed job must be rejected');
        } catch (ImportNotApplicable $e) {
            $this->assertContains($e->reason, ['not_confirmed']);
        }

        // 3) a forged "applying" status without a confirmation
        ImportJob::whereKey($job->id)->update(['status' => ImportJob::STATUS_APPLYING]);
        $this->expectException(ImportNotApplicable::class);
        try {
            app(ImportApplier::class)->apply($job->refresh());
        } finally {
            $this->assertSame($before, $this->canonicalCounts());
        }
    }

    public function test_a_confirmed_job_whose_staged_rows_contain_an_error_is_never_applied(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(2)), ImportJob::MODE_CREATE_ONLY);
        $before = $this->canonicalCounts();
        ImportRow::where('import_job_id', $job->id)->orderBy('row_number')->first()->update(['action' => 'error', 'errors' => [['code' => 'x']]]);
        ImportJob::whereKey($job->id)->update(['status' => 'applying', 'confirmed_by' => $editor->id, 'confirmed_at' => now()]);

        ApplyImportJob::dispatchSync($job->id);

        $this->assertSame($before, $this->canonicalCounts());
        $this->assertSame(ImportJob::STATUS_FAILED, $job->fresh()->status);
    }

    public function test_an_actor_who_lost_import_rights_before_the_apply_runs_writes_nothing(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(2)), ImportJob::MODE_CREATE_ONLY);
        $lock = Cache::lock(ImportApplier::LOCK_KEY, 60);
        $lock->get();
        app(ImportJobService::class)->confirm($editor, $job); // queued behind the lock
        $lock->release();
        $editor->forceFill(['role' => Role::Viewer])->save(); // demoted in the meantime

        ApplyImportJob::dispatchSync($job->id);

        $this->assertSame(0, Provider::count());
        $this->assertSame(ImportJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertSame('actor_not_authorized', $job->fresh()->failure_reason);
    }

    public function test_expired_previews_cannot_be_confirmed(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);
        $job->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasErrors('import');

        $this->assertSame(0, Provider::count());
    }

    // ------------------------------------------------------------------ confirm authorization and CSRF

    public function test_confirm_is_owner_or_admin_only_and_consistent_for_everyone_else(): void
    {
        $owner = $this->editor();
        $job = $this->startImport($owner, $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);

        $this->post(route('imports.confirm', $job))->assertRedirect(route('login'));
        $this->actingAs($this->viewer())->post(route('imports.confirm', $job))->assertForbidden();
        $this->actingAs($this->editor())->post(route('imports.confirm', $job))->assertNotFound();
        $this->assertSame(ImportJob::STATUS_READY, $job->fresh()->status);
        $this->assertSame(0, Provider::count());

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('imports.confirm', $job))->assertRedirect(route('imports.show', $job));

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame($admin->id, $job->confirmed_by, 'the confirming Admin is the actor');
        $this->assertSame($admin->id, Provider::first()->created_by);
    }

    public function test_a_job_can_only_be_confirmed_once(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(2)), ImportJob::MODE_CREATE_ONLY);

        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasErrors('import');

        $this->assertSame(2, Provider::count());
        $this->assertSame(1, AuditEvent::where('action', 'import.confirmed')->count());
    }

    public function test_confirm_enforces_csrf_in_the_real_middleware_stack(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);
        foreach (['confirm', 'cancel', 'retry'] as $action) {
            $route = app('router')->getRoutes()->getByName("imports.$action");
            $this->assertContains('web', $route->gatherMiddleware(), "imports.$action must be a web (CSRF) route");
            $this->assertSame(['POST'], $route->methods() === ['POST'] ? ['POST'] : $route->methods());
        }

        // Make the CSRF middleware behave as in production (it is bypassed while unit testing).
        $this->app->bind(
            PreventRequestForgery::class,
            fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            }
        );

        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertStatus(419);
        $this->assertSame(ImportJob::STATUS_READY, $job->fresh()->status);
        $this->assertSame(0, Provider::count());
    }

    // ------------------------------------------------------------------ cancel

    public function test_cancelling_a_ready_job_prevents_any_later_apply(): void
    {
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(2)), ImportJob::MODE_CREATE_ONLY);

        $this->actingAs($editor)->post(route('imports.cancel', $job))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasErrors('import');
        ApplyImportJob::dispatchSync($job->id);

        $this->assertSame(ImportJob::STATUS_CANCELLED, $job->fresh()->status);
        $this->assertSame(0, Provider::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'import.cancelled']);
    }

    public function test_cancelling_a_partially_applied_job_does_not_roll_back(): void
    {
        config(['providers.imports.chunk_size' => 5]);
        $editor = $this->editor();
        $job = $this->startImport($editor, $this->workbook($this->createRows(12)), ImportJob::MODE_CREATE_ONLY);
        $this->failOnChunk(1);
        try {
            app(ImportJobService::class)->confirm($editor, $job);
        } catch (RuntimeException) {
        }
        $this->assertSame(ImportJob::STATUS_INTERRUPTED, $job->fresh()->status);

        $this->actingAs($editor)->post(route('imports.cancel', $job))->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame(ImportJob::STATUS_CANCELLED, $job->status);
        $this->assertSame(5, Provider::count(), 'applied chunks stay applied');
        $this->assertSame(5, $job->applied_count);
        $this->actingAs($editor)->get(route('imports.show', $job))->assertOk()->assertSee(__('transfers.imports.partial_note', ['applied' => 5, 'total' => 12]));
    }

    public function test_only_cancellable_states_can_be_cancelled_and_only_by_the_owner_or_admin(): void
    {
        $editor = $this->editor();
        $job = $this->importAndApply($editor, $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);

        $this->actingAs($editor)->post(route('imports.cancel', $job))->assertSessionHasErrors('import');
        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->fresh()->status);

        $other = $this->startImport($editor, $this->workbook($this->createRows(1, 'Other')), ImportJob::MODE_CREATE_ONLY);
        $this->actingAs($this->editor())->post(route('imports.cancel', $other))->assertNotFound();
        $this->actingAs($this->viewer())->post(route('imports.cancel', $other))->assertForbidden();
        $this->assertSame(ImportJob::STATUS_READY, $other->fresh()->status);
        $this->actingAs($this->admin())->post(route('imports.cancel', $other))->assertSessionHasNoErrors();
        $this->assertSame(ImportJob::STATUS_CANCELLED, $other->fresh()->status);
    }

    public function test_retry_is_rejected_for_states_that_cannot_resume_and_for_other_users(): void
    {
        $editor = $this->editor();
        $job = $this->importAndApply($editor, $this->workbook($this->createRows(1)), ImportJob::MODE_CREATE_ONLY);

        $this->actingAs($editor)->post(route('imports.retry', $job))->assertSessionHasErrors('import');
        $this->actingAs($this->editor())->post(route('imports.retry', $job))->assertNotFound();
    }

    // ------------------------------------------------------------------ duplicate file in flight

    public function test_the_same_file_cannot_be_applied_again_while_an_earlier_copy_is_interrupted(): void
    {
        config(['providers.imports.chunk_size' => 3]);
        $editor = $this->editor();
        $path = $this->workbook($this->createRows(8));
        $first = $this->startImport($editor, $path, ImportJob::MODE_CREATE_ONLY);
        $this->failOnChunk(1);
        try {
            app(ImportJobService::class)->confirm($editor, $first);
        } catch (RuntimeException) {
        }
        $this->assertSame(ImportJob::STATUS_INTERRUPTED, $first->fresh()->status);

        $second = $this->startImport($editor, $path, ImportJob::MODE_CREATE_ONLY);

        $this->expectException(ImportActionRejected::class);
        app(ImportJobService::class)->confirm($editor, $second);
    }
}
