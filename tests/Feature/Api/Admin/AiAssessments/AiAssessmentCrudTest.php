<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\AiAssessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Library and details: create, validate, filter, update, duplicate, and a bounded list query. */
class AiAssessmentCrudTest extends AiAssessmentTestCase
{
    public function test_a_trainer_creates_an_assessment_owned_by_them(): void
    {
        $t = $this->trainer();

        $id = $this->postJson(self::URL, [
            'title' => 'Fire safety', 'type' => 'pre', 'content_language' => 'ar', 'difficulty' => 'hard',
            'audience' => 'New hires', 'objectives' => 'Know the exits', 'bloom_levels' => ['remember', 'apply'],
            'question_counts' => ['mcq' => 5, 'matching' => 0, 'scenario' => 2],
            'admin_id' => 999, 'status' => 'ready',
        ], $t['headers'])
            ->assertCreated()
            ->assertJsonPath('result.status', 'draft')
            ->assertJsonPath('result.question_counts.mcq', 5)
            ->assertJsonPath('result.question_counts.scenario', 2)
            ->assertJsonMissingPath('result.question_counts.matching')
            ->json('result.id');

        $this->assertSame($t['model']->id, AiAssessment::query()->find($id)->admin_id, 'owner is the caller, never the body');
    }

    public function test_invalid_details_are_refused(): void
    {
        $t = $this->trainer();
        $ok = ['title' => 'T1', 'type' => 'pre', 'content_language' => 'en', 'difficulty' => 'easy'];

        foreach ([
            'title'            => ['title' => ''],
            'type'             => ['type' => 'final'],
            'content_language' => ['content_language' => 'fr'],
            'difficulty'       => ['difficulty' => 'extreme'],
            'course_id'        => ['course_id' => 999999],
            'bloom_levels.0'   => ['bloom_levels' => ['memorise']],
            'question_counts'  => ['question_counts' => ['essay' => 3]],
            'question_counts.mcq' => ['question_counts' => ['mcq' => 21]],
        ] as $field => $override) {
            $this->postJson(self::URL, $override + $ok, $t['headers'])->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->postJson(self::URL, $ok + ['question_counts' => ['mcq' => 20, 'multi' => 20, 'short' => 20, 'matching' => 1]], $t['headers'])
            ->assertStatus(422)->assertJsonPath('errors.question_counts.0', __('ai_assessments.validation.questions_total', ['max' => 60]));
        $this->assertDatabaseCount('ai_assessments', 0);
    }

    public function test_update_saves_only_the_sent_fields(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);

        $this->putJson(self::URL."/{$a->id}", ['question_counts' => ['short' => 3]], $t['headers'])->assertOk()
            ->assertJsonPath('result.title', 'Workplace safety basics')
            ->assertJsonPath('result.question_counts.short', 3);
        $this->putJson(self::URL."/{$a->id}", ['type' => 'nope'], $t['headers'])->assertStatus(422);
    }

    public function test_the_library_filters_and_reports_counts_and_usage(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model'], ['title' => 'Arabic quiz', 'type' => 'quiz', 'content_language' => 'ar']);
        $this->question($a, ['status' => 'approved']);
        $this->question($a);
        $this->assessment($t['model'], ['title' => 'English post']);

        $this->getJson(self::URL.'?type=quiz', $t['headers'])->assertOk()
            ->assertJsonCount(1, 'result')
            ->assertJsonPath('result.0.questions_count', 2)
            ->assertJsonPath('result.0.approved_count', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.usage.limit', 30);
        $this->getJson(self::URL.'?search=english', $t['headers'])->assertJsonCount(1, 'result')->assertJsonPath('result.0.title', 'English post');
        $this->getJson(self::URL.'?search=%25', $t['headers'])->assertJsonCount(0, 'result');
        $this->getJson(self::URL.'?content_language=de', $t['headers'])->assertStatus(422);
        $this->getJson(self::URL.'?per_page=1000', $t['headers'])->assertStatus(422);
    }

    public function test_the_library_query_count_does_not_grow_with_rows(): void
    {
        $t = $this->trainer();
        $count = function () use ($t) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL, $t['headers'])->assertOk();
            // Reads only: the auth middleware's once-a-minute "last active"
            // UPDATE lands in whichever request crosses a minute boundary.
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => stripos(ltrim($q['query']), 'select') === 0));
            DB::disableQueryLog();

            return $n;
        };

        $this->question($this->assessment($t['model'], ['course_id' => \App\Models\Course::factory()->create()->id]));
        $count(); // warm-up: the auth middleware's once-a-minute "last active" writes
        $few = $count();
        for ($i = 0; $i < 12; $i++) {
            $this->question($this->assessment($t['model'], ['course_id' => \App\Models\Course::factory()->create()->id]));
        }
        $this->assertSame($few, $count(), 'no N+1 on the library');
    }

    public function test_the_editor_payload_never_carries_source_text_or_storage_paths(): void
    {
        $t = $this->trainer();
        $a = $this->withText($this->assessment($t['model']), str_repeat('secret course text ', 100));

        $json = $this->getJson(self::URL."/{$a->id}", $t['headers'])->assertOk()
            ->assertJsonPath('result.sources.0.chars', 1900)
            ->getContent();

        $this->assertStringNotContainsString('secret course text', $json);
        $this->assertStringNotContainsString('provider_file_uri', $json);
        $this->assertStringNotContainsString('"path"', $json);
    }

    public function test_duplicate_copies_questions_and_sources_with_their_own_files(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model'], ['status' => 'ready', 'version' => 2]);
        $this->postJson(self::URL."/{$a->id}/sources", ['file' => $this->docx(['Page'])], $t['headers'])->assertCreated();
        $this->question($a, ['status' => 'approved']);

        $copy = $this->postJson(self::URL."/{$a->id}/duplicate", [], $t['headers'])->assertCreated()
            ->assertJsonPath('result.title', 'Workplace safety basics (copy)')
            ->assertJsonPath('result.version', 1)
            ->assertJsonPath('result.status', 'ready')
            ->assertJsonPath('result.questions.0.status', 'approved')
            ->json('result');

        $version = $this->postJson(self::URL."/{$a->id}/duplicate", ['as_version' => true], $t['headers'])->assertCreated()
            ->assertJsonPath('result.title', 'Workplace safety basics')
            ->assertJsonPath('result.version', 3)->json('result');

        $this->assertCount(3, Storage::disk('private')->allFiles(), 'each copy owns its file');
        $this->deleteJson(self::URL."/{$a->id}", [], $t['headers'])->assertOk();
        $this->getJson(self::URL."/{$copy['id']}", $t['headers'])->assertOk()->assertJsonCount(1, 'result.sources');
        $this->assertCount(2, Storage::disk('private')->allFiles(), 'deleting the original keeps the copies\' files');
        $this->assertNull(AiAssessment::query()->find($version['id'])->duplicated_from_id, 'the link to a deleted original is cleared');
    }

    public function test_the_course_picker_is_bounded(): void
    {
        $t = $this->trainer();
        $this->getJson(self::URL.'/courses?search='.str_repeat('a', 300), $t['headers'])->assertOk()->assertJsonCount(0, 'result');
    }
}
